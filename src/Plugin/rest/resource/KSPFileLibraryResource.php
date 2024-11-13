<?php

namespace Drupal\ksp_filelibrary\Plugin\rest\resource;

use Drupal\Core\File\FileExists;
use Drupal\ksp_filelibrary\FileImporter;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\RequestHandler;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a resource for zip file import.
 *
 * @RestResource(
 *   id = "ksp_flielibrary_rest_resource",
 *   label = @Translation("KSP FileLibrary"),
 *   serialization_class = "Drupal\file\Entity\File",
 *   uri_paths = {
 *     "create" = "/docpackage"
 *   }
 * )
 */
class KSPFileLibraryResource extends ResourceBase {

  /**
   * The regex used to extract the filename from the content disposition header.
   *
   * @var string
   */
  const REQUEST_HEADER_FILENAME_REGEX = '@\bfilename(?<star>\*?)=\"(?<filename>.+)\"@';

  /**
   * The amount of bytes to read in each iteration when streaming file data.
   *
   * @var int
   */
  const BYTES_TO_READ = 8192;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The currently authenticated user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a FileUploadResource instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The currently authenticated user.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    FileSystemInterface $file_system,
    AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('file_system'),
      $container->get('current_user')
    );
  }

    

  /**
   * Creates a zip file from an endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return mixed
   *   \Drupal\rest\ResourceResponse
   *     The response containing the message of error.
   *   \Drupal\rest\ModifiedResourceResponse
   *     The response containing the success message.
   */
  public function post(Request $request) {
    try {
      $filename = $this->validateAndParseContentDispositionHeader($request);
      $this->validateFilename($filename);
    }
    catch (BadRequestHttpException $e) {
      return new ResourceResponse(['message' => $e->getMessage()], $e->getStatusCode());
    }

    $filename = str_replace(' ', '+', $filename);
    $destination = 'temporary://';
    // Check the destination file path is writable.
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
      $response = [
        'message' => $this->t('Destination file path is not writable')
      ];
      return new ResourceResponse($response, 500);
    }

    // Create the file.
    $file_uri = "{$destination}/{$filename}";

    try {
      $temp_file_path = $this->streamUploadData();
      $file_uri = $this->fileSystem->getDestinationFilename($file_uri, FileExists::Replace);

      // Begin building file entity.
      $file = File::create([]);
      $file->setOwnerId($this->currentUser->id());
      $file->setFilename($filename);
      $file->setMimeType('application/zip');
      $file->setFileUri($file_uri);

      $this->fileSystem->move($temp_file_path, $file_uri, FileExists::Replace);
    }
    catch (FileException $e) {
      return new ResourceResponse(['message' => $e->getMessage()], 500);
    }
    $file->save();

    try {
      // Do import.
      $importer = new FileImporter($file);
      $importer->importLinks();
    }
    catch (FileException $e) {
      return new ResourceResponse(['message' => $e->getMessage()], 500);
    }
    catch (\Exception $e) {
      return new ResourceResponse(['message' => $e->getMessage()], 500);
    }

    $response = [
      'message' => $this->t('File has been imported successfully.')
    ];
    if (!empty($importer->errorsProperties)) {
      $response = [
        'message' => $this->t('File has been imported successfully, here are a couple of wrong provided urls @urls.', [
          '@urls' => var_export($importer->errorsProperties, TRUE),
        ])
      ];
    }

    return new ModifiedResourceResponse($response, 201);
  }

  /**
   * {@inheritdoc}
   */
  public function availableMethods() {
    // Currently only POST is supported.
    return ['POST'];
  }

  /**
   * Validates and extracts the filename from the Content-Disposition header.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return string
   *   The filename extracted from the header.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when the 'Content-Disposition' request header is invalid.
   */
  protected function validateAndParseContentDispositionHeader(Request $request) {
    // Firstly, check the header exists.
    if (!$request->headers->has('content-disposition')) {
      throw new BadRequestHttpException('"Content-Disposition" header is required. A file name in the format "filename=FILENAME" must be provided', NULL, 400);
    }

    $content_disposition = $request->headers->get('content-disposition');

    // Parse the header value. This regex does not allow an empty filename.
    // i.e. 'filename=""'. This also matches on a word boundary so other keys
    // like 'not_a_filename' don't work.
    if (!preg_match(static::REQUEST_HEADER_FILENAME_REGEX, $content_disposition, $matches)) {
      throw new BadRequestHttpException('No filename found in "Content-Disposition" header. A file name in the format "filename=FILENAME" must be provided', NULL, 400);
    }

    // Check for the "filename*" format. This is currently unsupported.
    if (!empty($matches['star'])) {
      throw new BadRequestHttpException('The extended "filename*" format is currently not supported in the "Content-Disposition" header', NULL, 400);
    }

    $filename = $matches['filename'];

    return $this->fileSystem->basename($filename);
  }

  /**
   * Validates the file.
   *
   * @param string $filename
   *   The file name to validate.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when there are file validation errors.
   */
  protected function validateFilename($filename) {
    // Validate file zip extension.
    $regex = '/\.zip$/i';
    if (!preg_match($regex, $filename)) {
      throw new BadRequestHttpException('Only zip files are allowed.', NULL, 400);
    }
  }

  /**
   * Streams file upload data to temporary file and moves to file destination.
   *
   * @return string
   *   The temp file path.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   Thrown when input data cannot be read, the temporary file cannot be
   *   opened, or the temporary file cannot be written.
   */
  protected function streamUploadData() {
    // 'rb' is needed so reading works correctly on Windows environments too.
    $file_data = fopen('php://input', 'rb');
    $temp_file_path = $this->fileSystem->tempnam('temporary://', 'file');
    $temp_file = fopen($temp_file_path, 'wb');
    if ($temp_file) {
      while (!feof($file_data)) {
        $read = fread($file_data, static::BYTES_TO_READ);
        if ($read === FALSE) {
          fclose($temp_file);
          fclose($file_data);
          throw new FileException('Input file data could not be read');
        }
        if (fwrite($temp_file, $read) === FALSE) {
          fclose($temp_file);
          fclose($file_data);
          throw new FileException('Temporary file data could not be written');
        }
      }
      fclose($temp_file);
    }
    else {
      fclose($file_data);
      throw new FileException('Temporary file could not be opened');
    }
    fclose($file_data);

    return $temp_file_path;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBaseRoute($canonical_path, $method) {
    return new Route($canonical_path, [
      '_controller' => RequestHandler::class . '::handleRaw',
    ],
      $this->getBaseRouteRequirements($method),
      [],
      '',
      [],
      [$method]
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getBaseRouteRequirements($method) {
    $requirements = parent::getBaseRouteRequirements($method);

    // Enforce the 'application/octet-stream' Content-Type header.
    $requirements['_content_type_format'] = 'bin';

    return $requirements;
  }

}
