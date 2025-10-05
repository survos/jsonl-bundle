<?php

// load and translate news

declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use jcobhams\Jsonl\Jsonl;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class JsonlService
{
    private Jsonl $Jsonl;
    public function __construct(
        private ?string $apiKey=null,
        private array $config=[],
        private ?CacheInterface $cache=null,
        private ?LoggerInterface $logger=null,
        private ?SluggerInterface $asciiSlugger=null,
    )
    {
        // can any calls be made without the key?
        if ($apiKey !== null) {
            $this->Jsonl = new Jsonl($this->apiKey);
        } else {
            throw new \RuntimeException('You must provide a API key');
        }

    }

    public function getSources(string $language): object|array
    {
        $sources = $this->cache->get('sources_' . $language,
            fn(CacheItem $item) => $this->Jsonl->getSources(language: $language));
        return $sources->sources;
    }

    public function loadSources(string $language)
    {
        $sources = $this->getSources($language);
        dd($sources); // where should we move to model?
        foreach ($sources as $data) {
            $data = (array)$data;
            $origLanguage = $data['language'];
            if (!$source = $this->entityManager->getRepository(Source::class)
                ->findOneBy(['code' => $data['id']])) {
                $source = (new Source())
                    ->setCode($data['id']);
                $this->entityManager->persist($source);
            }
            // set the originals, not really translations
            $source->setDefaultLocale($origLanguage);
            $source
                ->translate($origLanguage)->setDescription($data['description']);
            $source
                ->translate($origLanguage)->setName($data['name']);
            $source
                ->setUrl($data['url'])
                ->setCountry($data['country'])
                ->setLanguage($data['language']);
        }
        $this->entityManager->flush();

    }

    public function getLanguages()
    {
        return $this->Jsonl->getLanguages();
    }

    public function search(string $language, string $q='tobacco', bool $topOnly=false)
    {
        $key = sprintf("art_%s_%s", $language, $this->asciiSlugger->slug($q));
//        return $this->Jsonl->getEverything($q, language: $language);
        // @todo: set the cache time
        $articles = $this->cache->get($key, fn(CacheItem $cacheItem) =>
            $this->Jsonl->getEverything($q, language: $language)
        );
        return $articles;
    }

    public function loadArticles(string $language, string $q='tobacco')
    {
        $slugger = new AsciiSlugger();
        $key = sprintf("art_%s_%s", $language, $slugger->slug($q));
        $articles = $this->cache->get($key, fn(CacheItem $cacheItem) =>
            $this->Jsonl->getEverything($q, language: $language)
        );
        dd($articles);
        return $articles;
        foreach ($articles->articles as $idx => $a) {
            $s = $a->source;
            if (!$s->id) {
                continue;
            }
            dump('orig: ' . $language . '/' . $a->title);
            $source = $this->sourceRepository->findOneBy(['code' => $s->id]);
            if (!$source) {
                continue;
            }

            $article = $this->articleRepository->get($a->url);
            assert($source, $s->id);
//            $source->addArticle($article); // update count?
            $article->setSource($source);
            $article->setUrl($a->url)
                ->setAuthor($a->author)
                ->setPublishedAt(new \DateTimeImmutable($a->publishedAt))
                ->setLanguage($language)
                ->setDefaultLocale($language)
            ;
            $article
                ->translate($language)->setDescription($a->description)
            ;
            $article
                ->translate($language)->setTitle($a->title);
            $article->mergeNewTranslations();
        }
        $this->entityManager->flush();
    }

}
