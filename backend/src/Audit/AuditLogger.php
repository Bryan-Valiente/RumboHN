<?php

declare(strict_types=1);

namespace Rumbo\Backend\Audit;

use PDO;
use Rumbo\Backend\Support\Dates;
use Rumbo\Backend\Support\SystemClock;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, scalar|bool|null> $metadata */
    public function record(
        ?string $actorUserId,
        ?string $subjectUserId,
        string $action,
        ?string $entityType,
        ?string $entityId,
        string $requestId,
        array $metadata = [],
    ): void {
        $json = $metadata === []
            ? null
            : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, subject_user_id, action, entity_type, entity_id, request_id, metadata_json, created_at)
             VALUES
                (:actor_user_id, :subject_user_id, :action, :entity_type, :entity_id, :request_id, :metadata_json, :created_at)'
        );
        $statement->execute([
            ':actor_user_id' => $actorUserId,
            ':subject_user_id' => $subjectUserId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':request_id' => $requestId,
            ':metadata_json' => $json,
            ':created_at' => Dates::database((new SystemClock())->now()),
        ]);
    }
}
