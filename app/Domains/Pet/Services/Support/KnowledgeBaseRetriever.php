<?php

namespace App\Domains\Pet\Services\Support;

use App\Domains\Pet\Models\KnowledgeArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Recuperación por keywords (RAG v1) sobre la base de conocimiento publicada.
 *
 * Arquitectura abierta a embeddings/vector search: cuando exista, basta cambiar
 * la implementación de search() sin tocar a quien la consume (SupportAiService).
 */
class KnowledgeBaseRetriever
{
    /** Palabras vacías que no aportan a la búsqueda. */
    private const STOPWORDS = [
        'el','la','los','las','un','una','unos','unas','de','del','al','a','y','o','u',
        'que','como','cómo','para','por','con','sin','mi','mis','tu','tus','su','sus',
        'es','esta','este','esto','en','se','me','le','lo','si','no','ya','muy','mas','más',
        'hacer','hago','puedo','quiero','necesito','ayuda','tengo','sobre','the','to','of',
    ];

    /**
     * Devuelve los artículos más relevantes para la consulta, ordenados por score.
     *
     * @return Collection<int, KnowledgeArticle>
     */
    public function search(string $query, int $limit = 3, string $brand = 'roke_pet'): Collection
    {
        $terms = $this->tokenize($query);
        if (empty($terms)) {
            return collect();
        }

        $articles = KnowledgeArticle::query()
            ->forBrand($brand)
            ->published()
            ->get();

        return $articles
            ->map(function (KnowledgeArticle $article) use ($terms) {
                $article->setAttribute('_score', $this->score($article, $terms));
                return $article;
            })
            ->filter(fn (KnowledgeArticle $a) => $a->getAttribute('_score') > 0)
            ->sortByDesc(fn (KnowledgeArticle $a) => $a->getAttribute('_score'))
            ->take($limit)
            ->values();
    }

    private function score(KnowledgeArticle $article, array $terms): int
    {
        $title    = $this->normalize($article->title);
        $keywords = $this->normalize((string) $article->keywords);
        $tags     = $this->normalize(implode(' ', (array) $article->tags));
        $body     = $this->normalize(Str::limit((string) $article->content, 4000, ''));

        $score = 0;
        foreach ($terms as $term) {
            if (str_contains($title, $term))    $score += 6;  // el título pesa más
            if (str_contains($keywords, $term)) $score += 4;
            if (str_contains($tags, $term))     $score += 3;
            if (str_contains($body, $term))     $score += 1;
        }

        return $score;
    }

    /** @return array<int, string> */
    private function tokenize(string $text): array
    {
        $normalized = $this->normalize($text);
        $words = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->filter(fn ($w) => strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    private function normalize(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
}
