<?php

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Service\JsonlService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\VarDumper\Caster\SymfonyCaster;
use Zenstruck\Bytes;

#[AsCommand('jsonl:list', 'list jsonl sources and articles (various endpoints)')]
final class JsonlListCommand
{

    public function __construct(
        private readonly JsonlService $JsonlService,
    )
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyCaster                                                                                          $io,
        #[Argument(description: 'endpoint (source, search)')] string        $endpoint='',
        #[Option(description: 'filter by top')] bool $top = false,
        #[Option(description: 'search string')] ?string $q=null,
        #[Option(description: '2-letter language code')] string $locale='en',

    ): int
    {
        if ($q) {
            $articles = $this->JsonlService->loadArticles($locale, $q);
            $table = new Table($io);
            $table->setHeaderTitle($locale . "/" . $q);
            $headers = ['Name', 'StorageUsed','FilesStored','Id'];
            $table->setHeaders($headers);
            foreach ($zones as $zone) {
                $row = [];
                foreach ($headers as $header) {
                    $row[$header] = $zone[$header];
                }
                $id = $row['Id'];
                $row['Id'] = "<href=https://dash.jsonl.net/storage/$id/file-manager>$id</>";

                $table->addRow($row);
            }
            $table->render();
            return self::SUCCESS;
        }

        if (!$zoneName) {
            $zoneName = $this->JsonlService->getStorageZone();
        }
        assert($zoneName, "missing zone name");

        $edgeStorageApi = $this->JsonlService->getEdgeApi($zoneName);
        $list = $edgeStorageApi->listFiles(
            storageZoneName: $zoneName,
            path: $path
        )->getContents();

        // @todo: see if https://www.php.net/manual/en/class.numberformatter.php works to remove the dependency
        $table = new Table($io);
        $table->setHeaderTitle($zoneName . "/" . $path);
        $headers = ['ObjectName', 'Path','Length', 'Url'];
        $table->setHeaders($headers);
        foreach ($list as $file) {
            $row = [];
            foreach ($headers as $header) {
                $row[$header] = $file[$header]??null;
            }
            $row['Length'] = Bytes::parse($row['Length']); // "389.79 GB"
            $row['Url'] = "<href=https://symfony.com>Symfony Homepage</>";
            $table->addRow($row);
        }
        $table->render();
        $this->io()->output()->writeln('<href=https://symfony.com>Symfony Homepage</>');

        $io->success($this->getName() . ' success ' . $zoneName);
        return self::SUCCESS;
    }




}
