<?php

declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use Throwable;

final class MarketingContentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function publicTestimonials(): array
    {
        $statement = $this->pdo->query(
            'SELECT name, context, quote, result, source_url
             FROM marketing_testimonials
             WHERE authorization_confirmed = 1 AND published = 1
             ORDER BY slot ASC LIMIT 3'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function testimonialsForAdmin(): array
    {
        return $this->pdo->query(
            'SELECT slot, name, context, quote, result, source_url, authorization_confirmed, published
             FROM marketing_testimonials ORDER BY slot ASC LIMIT 3'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string,mixed>> $testimonials */
    public function replaceTestimonials(int $actorId, array $testimonials): void
    {
        if (!$this->isActiveAdmin($actorId)) {
            throw new DomainException('An active administrator is required.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT marketing_content');
        }

        try {
            $this->pdo->exec('DELETE FROM marketing_testimonials');
            $statement = $this->pdo->prepare(
                'INSERT INTO marketing_testimonials
                 (slot, name, context, quote, result, source_url, authorization_confirmed, published, updated_by)
                 VALUES (:slot, :name, :context, :quote, :result, :source_url, :authorization, :published, :actor)'
            );
            foreach ($testimonials as $testimonial) {
                $statement->execute([
                    'slot' => $testimonial['slot'],
                    'name' => $testimonial['name'],
                    'context' => $testimonial['context'],
                    'quote' => $testimonial['quote'],
                    'result' => $testimonial['result'] !== '' ? $testimonial['result'] : null,
                    'source_url' => $testimonial['source_url'] !== '' ? $testimonial['source_url'] : null,
                    'authorization' => $testimonial['authorization_confirmed'] ? 1 : 0,
                    'published' => $testimonial['published'] ? 1 : 0,
                    'actor' => $actorId,
                ]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT marketing_content');
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT marketing_content');
                $this->pdo->exec('RELEASE SAVEPOINT marketing_content');
            }
            throw $exception;
        }
    }

    private function isActiveAdmin(int $actorId): bool
    {
        if ($actorId < 1) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT role, status FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $actorId]);
        $actor = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($actor) && ($actor['role'] ?? null) === 'admin' && ($actor['status'] ?? null) === 'active';
    }
}
