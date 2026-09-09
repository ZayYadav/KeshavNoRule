<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use RuntimeException;
use Throwable;

final class BroadcastService
{
    private const MAX_MESSAGE_BYTES = 1000;
    private const BATCH_SIZE = 12;
    private const MAX_ATTEMPTS = 3;

    public static function create(
        array $actor,
        string $message,
        bool $panel,
        bool $linkedTelegram,
        bool $guestTelegram
    ): array {
        Auth::requireRole($actor, 'owner');

        $message = trim($message);
        $bytes = strlen($message);

        if ($bytes < 1 || $bytes > self::MAX_MESSAGE_BYTES) {
            throw new RuntimeException('Announcement must be between 1 and 1000 bytes.');
        }

        if (!$panel && !$linkedTelegram && !$guestTelegram) {
            throw new RuntimeException('Select at least one announcement audience.');
        }

        Security::rateLimit(
            'owner-announcement-create',
            12,
            3600,
            (string)$actor['id']
        );

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                "INSERT INTO announcement_broadcasts(
                    created_by,message,panel_enabled,target_linked,target_guests,
                    status,total_recipients,sent_count,failed_count
                 ) VALUES(?,?,?,?,?,'queued',0,0,0)"
            )->execute([
                (int)$actor['id'],
                $message,
                $panel ? 1 : 0,
                $linkedTelegram ? 1 : 0,
                $guestTelegram ? 1 : 0,
            ]);

            $broadcastId = (int)$pdo->lastInsertId();

            if ($linkedTelegram || $guestTelegram) {
                $where = [];
                if ($linkedTelegram) {
                    $where[] = 'linked_user_id IS NOT NULL';
                }
                if ($guestTelegram) {
                    $where[] = 'linked_user_id IS NULL';
                }

                $sql = "INSERT IGNORE INTO announcement_recipients(
                            broadcast_id,telegram_user_id,chat_id,audience,status,attempts
                        )
                        SELECT ?,id,chat_id,
                               CASE WHEN linked_user_id IS NULL THEN 'guest' ELSE 'linked' END,
                               'pending',0
                        FROM telegram_users
                        WHERE chat_id>0
                          AND (".implode(' OR ', $where).")";

                $pdo->prepare($sql)->execute([$broadcastId]);
            }

            $q = $pdo->prepare(
                'SELECT COUNT(*) FROM announcement_recipients WHERE broadcast_id=?'
            );
            $q->execute([$broadcastId]);
            $total = (int)$q->fetchColumn();

            $status = $total > 0 ? 'queued' : 'completed';
            $finished = $total > 0 ? null : date('Y-m-d H:i:s');

            $pdo->prepare(
                'UPDATE announcement_broadcasts
                 SET total_recipients=?,status=?,finished_at=?
                 WHERE id=?'
            )->execute([$total, $status, $finished, $broadcastId]);

            Security::audit((int)$actor['id'], 'announcement_created', [
                'broadcast_id'=>$broadcastId,
                'panel'=>$panel,
                'linked_telegram'=>$linkedTelegram,
                'guest_telegram'=>$guestTelegram,
                'telegram_recipients'=>$total,
            ]);

            $pdo->commit();

            if ($panel) {
                PanelControl::setAnnouncement($actor, $message);
            }

            return self::status($broadcastId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function clearPanel(array $actor): void
    {
        Auth::requireRole($actor, 'owner');
        PanelControl::setAnnouncement($actor, '');
        Security::audit((int)$actor['id'], 'announcement_panel_cleared');
    }

    public static function processBatch(array $actor, int $broadcastId): array
    {
        Auth::requireRole($actor, 'owner');

        if ($broadcastId <= 0) {
            throw new RuntimeException('Invalid broadcast.');
        }

        Security::rateLimit(
            'owner-announcement-process',
            240,
            3600,
            (string)$actor['id']
        );

        $pdo = Database::pdo();

        $job = self::jobForOwner($broadcastId);

        if (in_array($job['status'], ['completed','partial','cancelled'], true)) {
            return self::status($broadcastId);
        }

        // Recover recipients left in sending state by an interrupted request.
        $pdo->prepare(
            "UPDATE announcement_recipients
             SET status='pending',last_error='Recovered interrupted delivery'
             WHERE broadcast_id=?
               AND status='sending'
               AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)"
        )->execute([$broadcastId]);

        $q = $pdo->prepare(
            "SELECT id,chat_id,attempts
             FROM announcement_recipients
             WHERE broadcast_id=?
               AND status='pending'
               AND attempts<?
             ORDER BY id
             LIMIT ".self::BATCH_SIZE
        );
        $q->execute([$broadcastId, self::MAX_ATTEMPTS]);
        $rows = $q->fetchAll() ?: [];

        if (!$rows) {
            self::finishIfDone($broadcastId);
            return self::status($broadcastId);
        }

        $pdo->prepare(
            "UPDATE announcement_broadcasts
             SET status='sending',
                 started_at=COALESCE(started_at,NOW())
             WHERE id=?"
        )->execute([$broadcastId]);

        $html = "📢 <b>TEAM DARK ANNOUNCEMENT</b>\n\n"
            .htmlspecialchars(
                (string)$job['message'],
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            ."\n\n<i>Official message from Team Dark.</i>";

        foreach ($rows as $row) {
            $recipientId = (int)$row['id'];
            $attempt = (int)$row['attempts'] + 1;

            $claim = $pdo->prepare(
                "UPDATE announcement_recipients
                 SET status='sending',attempts=?,updated_at=NOW()
                 WHERE id=? AND status='pending'"
            );
            $claim->execute([$attempt, $recipientId]);

            if ($claim->rowCount() !== 1) {
                continue;
            }

            $ok = false;
            try {
                $ok = TelegramService::sendPrivateMessage(
                    (int)$row['chat_id'],
                    $html
                );
            } catch (Throwable $e) {
                error_log(
                    'Announcement delivery failure recipient '.$recipientId
                    .' '.get_class($e)
                );
            }

            if ($ok) {
                $pdo->prepare(
                    "UPDATE announcement_recipients
                     SET status='sent',sent_at=NOW(),last_error=NULL,updated_at=NOW()
                     WHERE id=?"
                )->execute([$recipientId]);
            } elseif ($attempt >= self::MAX_ATTEMPTS) {
                $pdo->prepare(
                    "UPDATE announcement_recipients
                     SET status='failed',last_error='Telegram delivery failed',updated_at=NOW()
                     WHERE id=?"
                )->execute([$recipientId]);
            } else {
                $pdo->prepare(
                    "UPDATE announcement_recipients
                     SET status='pending',last_error='Telegram delivery retry queued',updated_at=NOW()
                     WHERE id=?"
                )->execute([$recipientId]);
            }

            // Keep outbound delivery gentle and predictable.
            usleep(70000);
        }

        self::refreshCounts($broadcastId);
        self::finishIfDone($broadcastId);

        return self::status($broadcastId);
    }

    public static function recent(int $limit = 12): array
    {
        $limit = max(1, min(30, $limit));

        try {
            $q = Database::pdo()->query(
                "SELECT b.*,u.username creator_username
                 FROM announcement_broadcasts b
                 JOIN users u ON u.id=b.created_by
                 ORDER BY b.id DESC
                 LIMIT ".$limit
            );
            return $q->fetchAll() ?: [];
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1146) {
                return [];
            }
            throw $e;
        }
    }

    public static function status(int $broadcastId): array
    {
        $q = Database::pdo()->prepare(
            "SELECT id,message,panel_enabled,target_linked,target_guests,status,
                    total_recipients,sent_count,failed_count,created_at,started_at,finished_at
             FROM announcement_broadcasts
             WHERE id=?
             LIMIT 1"
        );
        $q->execute([$broadcastId]);
        $row = $q->fetch();

        if (!$row) {
            throw new RuntimeException('Broadcast not found.');
        }

        $total = (int)$row['total_recipients'];
        $sent = (int)$row['sent_count'];
        $failed = (int)$row['failed_count'];

        return [
            'id'=>(int)$row['id'],
            'status'=>$row['status'],
            'total'=>$total,
            'sent'=>$sent,
            'failed'=>$failed,
            'pending'=>max(0, $total - $sent - $failed),
            'panel'=>(bool)$row['panel_enabled'],
            'linked'=>(bool)$row['target_linked'],
            'guests'=>(bool)$row['target_guests'],
            'created_at'=>$row['created_at'],
            'finished_at'=>$row['finished_at'],
        ];
    }

    private static function jobForOwner(int $broadcastId): array
    {
        $q = Database::pdo()->prepare(
            'SELECT * FROM announcement_broadcasts WHERE id=? LIMIT 1'
        );
        $q->execute([$broadcastId]);
        $row = $q->fetch();

        if (!$row) {
            throw new RuntimeException('Broadcast not found.');
        }

        return $row;
    }

    private static function refreshCounts(int $broadcastId): void
    {
        Database::pdo()->prepare(
            "UPDATE announcement_broadcasts b
             SET sent_count=(
                    SELECT COUNT(*) FROM announcement_recipients r
                    WHERE r.broadcast_id=b.id AND r.status='sent'
                 ),
                 failed_count=(
                    SELECT COUNT(*) FROM announcement_recipients r
                    WHERE r.broadcast_id=b.id AND r.status='failed'
                 )
             WHERE b.id=?"
        )->execute([$broadcastId]);
    }

    private static function finishIfDone(int $broadcastId): void
    {
        self::refreshCounts($broadcastId);
        $state = self::status($broadcastId);

        if ($state['pending'] > 0) {
            return;
        }

        $status = $state['failed'] > 0 ? 'partial' : 'completed';

        Database::pdo()->prepare(
            'UPDATE announcement_broadcasts
             SET status=?,finished_at=COALESCE(finished_at,NOW())
             WHERE id=?'
        )->execute([$status, $broadcastId]);
    }
}
