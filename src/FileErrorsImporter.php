<?php

namespace Drupal\ksp_filelibrary;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use EasyRdf\Graph as EasyRdf_Graph;
use EasyRdf\Parser\Turtle as EasyRdf_Parser_Turtle;
use ZipArchive;
use Drupal\Component\Utility\UrlHelper;

/**
 * Class FileImporter.
 */
class FileErrorsImporter {

  /**
   * @var \Drupal\file\Entity\File
   */
  protected $archive;

  /**
   * @var string
   */
  protected $archiveDirectory;

  /**
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fs;

  /**
   * @var EasyRdf_Graph
   */
  protected $graph;

  /**
   * @var array
   */
  protected $graphIndex = [];

  /**
   * @var array
   */
  protected $mainProperties = [];

  /**
   * @var array
   */
  public $errorsProperties = [];

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * FileImporter constructor.
   *
   * @param \Drupal\file\Entity\File $file
   */
  public function __construct(File $file) {
    $this->archive = $file;
    $this->fs = \Drupal::service('file_system');
    $this->logger = \Drupal::logger('ksp_file_errors_importer');
  }

  /**
   * Imports all objects from RDF.
   *
   * @throws \Exception
   */
  public function importErrorsLinks() {
    $this->logger->info('Import errors started.');
    $this->extractArchive();
    $this->importFiles();
  }

  /**
   * Extract archive to directory and store the path in variable.
   *
   * @throws \Exception
   */
  protected function extractArchive() {
    $this->logger->info('Extracting archive.');
    $zipArchive = new ZipArchive();

    $path = $this->fs->realpath($this->archive->getFileUri());

    $this->logger->info('just about to open archive. path: ' . var_export($path, TRUE));
    $this->logger->info('file size of archive is: ' . var_export($this->archive->getSize(), TRUE));

    $result = $zipArchive->open($path);

    $this->logger->info('Tried to open. result: ' . var_export($result, TRUE));

    if ($result === TRUE) {
      $dir = \Drupal::root() . '/' . $this->fs->getTempDirectory() . '/' . date('dmYHis');
      $zipArchive->extractTo($dir);
      $this->archiveDirectory = $dir;

      $manifestFileMask = '/.*\\.n3$/';
      $files = $this->fs->scanDirectory($dir, $manifestFileMask);

      if (empty($files)) {
        $this->logger->error("Manifest file doesn't exist.");
        throw new FileException("Couldn't find manifest file in archive.");
      }

      $manifestFile = reset($files);
      $script_folder = getcwd() . "/../scripts/";
      if (getenv('ENVIRONMENT') == 'prod' || getenv('ENVIRONMENT') == 'stage') {
        $script_folder = $this->fs->realpath("private://");
      }

      $this->logger->info("Script folder @folder", array('@folder' => $script_folder));
      // Split file.
      $r = shell_exec( $script_folder . "/n3splitter $manifestFile->uri");
      // Prev split -> $r = shell_exec( $script_folder . "/n3splitter.sh $manifestFile->uri");.
    }
    else {
      $this->logger->error('Failed extracting archive.');
      throw new \Exception('Failed extracting archive.');
    }

    $zipArchive->close();
  }

  /**
   * Main function of files import.
   *
   * @throws
   */
  public function importFiles() {
    $this->logger->info('Import Error codes start.');
    $importedCount = 0;
    $failedCount = 0;

    $queue = \Drupal::queue('cron_redirects_processor');

    $links = $this->getGraph()->allOfType('<http://purl.org/dc/terms/BibliographicResource>');
    foreach ($links as $link) {
      $filePath = $link->get('<https://www.w3.org/Addressing/url>')->getValue();
      $fileLocation = str_replace('file:///', $this->archiveDirectory . '/', $filePath);

      if (!file_exists($fileLocation)) {
        $this->logger->error("Reference file '@reference' doesn't exist.", ['@reference' => $filePath]);

        throw new FileException("Referenced file '{$filePath}' not found. Make sure file exists in archive and the name is correct.");
      }

      $referenceUrl = $link->getUri();
      if (UrlHelper::isValid($referenceUrl, TRUE)) {
        // Save file.
        [$fid, $redirectUrl] = $this->saveFile($fileLocation, 1, $referenceUrl);

        if (empty($fid)) {
          $this->logger->error("File '@file' save failed.", ['@file' => $referenceUrl]);
          $failedCount++;
        }
        else {
          $referencedItems = $this->graph->resourcesMatching('dcterms:references', [
            'value' => $referenceUrl,
            'type' => 'uri'
          ]);
          if (!empty($referencedItems)) {
            foreach ($referencedItems as $refItem) {
              $majorVersionFiles = [];

              $importedCount++;
              $uuids = $this->getMajorVersionByUuid($refItem->getUri());
              if (!empty($uuids)) {
                foreach ($uuids as $uuid) {
                  $uuidsFiles = $uuid->get('field_files')->getValue();
                  $uuidsFiles[] = [
                    'target_id' => $fid,
                  ];
                  $uuid->set('field_files', $uuidsFiles);
                  $uuid->save();
                }
              }
            }
          }

          $queue->createItem([
            'RealUrl' => $referenceUrl,
            'RedirectUrl' => $redirectUrl,
            'MediaId' => $fid,
          ]);
        }
      }
      else {
          $this->errorsProperties[] = $referenceUrl;
        }
      }

      $this->logger->info('Import of files finished. Imported @importedCount items, @failedCount items', [
        '@importedCount' => $importedCount,
        '@failedCount' => $failedCount
      ]);
    }

    /**
     * Return majorversion by $uuid.
     *
     * @param $uuid
     *
     * @return \Drupal\node\Entity\Node|null
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    protected function getMajorVersionByUuid($uuid) {
      $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery();

      $nids = $query->condition('type', ['serviceuuid'], 'IN')
        ->condition('field_uuid', $uuid . '%', 'LIKE')
        //->range(0, 1)
        ->accessCheck(FALSE)
        ->execute();

      return (!empty($nids) ? Node::loadMultiple($nids) : NULL);
    }

    /**
     * @param $fileLocation
     * @param $uid
     * @param $referenceUrl
     *
     * @return int|string|null
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    protected function saveFile($fileLocation, $uid, $referenceUrl) {
      $destination = 'public://error-codes/' . date('dmYHi') . '/';
      $fileData = file_get_contents($fileLocation);
      $a = $this->fs->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);
      $filename = $this->fs->basename($fileLocation);
      $file = \Drupal::service('file.repository')->writeData($fileData, $destination . $filename);

      if ($file) {
        $file->setPermanent();
        $file->save();
        $media = Media::create([
          'bundle' => 'html',
          'uid' => $uid,
          'field_media_html_1' => [
            'target_id' => $file->id(),
          ],
        ]);
        $media->set('name', $filename)
          ->set('field_reference_url', $referenceUrl)
          ->setUnpublished()
          ->save();

        $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());

        return [$media->id(), $url];
      }

      return NULL;
    }

    protected function getGraph() {
      if (empty($this->graph)) {
        $manifestFileMask = '/.*\\.split.n3$/';
        $files = $this->fs->scanDirectory($this->archiveDirectory, $manifestFileMask);

        if (empty($files)) {
          $this->logger->error("Manifest file doesn't exist. @dir", array('@dir' => $this->archiveDirectory));
          throw new FileException("Couldn't find manifest file in archive.");
        }

        // Sort files.
        uasort($files, function($a, $b) {
          $a_name = isset($a->name) ? $a->name : 0;
          $b_name = isset($b->name) ? $b->name : 0;
          if ($a_name == $b_name) {
            return 0;
          }
          return ($a_name < $b_name) ? -1 : 1;

        });

        $this->graph = new EasyRdf_Graph();
        end($files);
        $lastElementKey = key($files);
        foreach ($files as $key => $file) {
          $parser = new EasyRdf_Parser_Turtle();
          $data = file_get_contents($file->uri);
          if ($key == $lastElementKey) {
            $data = rtrim($data, "\n\t.");
          }

          try {
            $current = new EasyRdf_Graph();
            $parser->parse($current, $data, 'turtle', $file->uri);

            $this->graph = $this->mergeGraphs($this->graph, $current);
          }
          catch (\Exception $e) {
            $this->logger->error('Filed to parse message: @message',
              [
                '@message' => $e->getMessage(),
              ]);

          }
        }
      }

      return $this->graph;
    }

    function mergeGraphs(EasyRdf_Graph $graph1, EasyRdf_Graph $graph2)
    {
      $data1 = $graph1->toRdfPhp();
      $data2 = $graph2->toRdfPhp();

      $merged = array_merge_recursive($data1, $data2);
      unset($data1, $data2);

      return new EasyRdf_Graph('urn:easyrdf:merged', $merged, 'php');
    }

  }
