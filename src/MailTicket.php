<?php

namespace GlpiPlugin\Mailtoticket;

use CommonDBTM;
use Session;

/**
 * Estado de cada e-mail no plugin: com chamado aberto ou descartado.
 * A chave do e-mail é o sha1 do Message-ID. E-mail sem registro = pendente.
 */
class MailTicket extends CommonDBTM
{
    public const STATUS_TICKET    = 1;
    public const STATUS_DISCARDED = 2;
    public const STATUS_FOLLOWUP  = 3; // virou acompanhamento de um chamado existente

    public static $rightname = 'ticket';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_mailtoticket_tickets';
    }

    /**
     * @param string[] $keys
     * @return array<string,array{status:int,tickets_id:int}> message_key => estado
     */
    public static function getStates(int $mailboxes_id, array $keys): array
    {
        global $DB;

        if ($keys === []) {
            return [];
        }
        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_mailtoticket_mailboxes_id' => $mailboxes_id, 'message_key' => $keys],
            ]) as $row
        ) {
            $out[$row['message_key']] = ['status' => (int) ($row['status'] ?: self::STATUS_TICKET), 'tickets_id' => (int) $row['tickets_id']];
        }
        return $out;
    }

    public static function getState(int $mailboxes_id, string $key): ?array
    {
        return self::getStates($mailboxes_id, [$key])[$key] ?? null;
    }

    /** Último chamado ligado a algum destes e-mails (conversa). */
    public static function ticketByMessageKeys(int $mailboxes_id, array $keys): ?int
    {
        global $DB;

        $keys = self::validKeys($keys);
        if ($keys === []) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'plugin_mailtoticket_mailboxes_id' => $mailboxes_id,
                'message_key' => $keys,
                'status'      => [self::STATUS_TICKET, self::STATUS_FOLLOWUP],
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();
        return $row ? (int) $row['tickets_id'] : null;
    }

    /** Último chamado criado/atualizado a partir de e-mail com o mesmo assunto normalizado. */
    public static function ticketBySubjectKey(int $mailboxes_id, string $subject_key): ?int
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'plugin_mailtoticket_mailboxes_id' => $mailboxes_id,
                'subject_key' => $subject_key,
                'status'      => [self::STATUS_TICKET, self::STATUS_FOLLOWUP],
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();
        return $row ? (int) $row['tickets_id'] : null;
    }

    /** @param string[] $keys @return int quantidade descartada */
    public static function discardMails(int $mailboxes_id, array $keys): int
    {
        $n    = 0;
        $item = new self();
        foreach (self::validKeys($keys) as $key) {
            if (self::getState($mailboxes_id, $key) !== null) {
                continue; // já tem chamado ou já descartado
            }
            if ($item->add([
                'plugin_mailtoticket_mailboxes_id' => $mailboxes_id,
                'message_key'                      => $key,
                'status'                           => self::STATUS_DISCARDED,
                'tickets_id'                       => 0,
                'users_id'                         => (int) Session::getLoginUserID(),
            ])) {
                $n++;
            }
        }
        return $n;
    }

    /** @param string[] $keys @return int quantidade restaurada */
    public static function restoreMails(int $mailboxes_id, array $keys): int
    {
        global $DB;

        $keys = self::validKeys($keys);
        if ($keys === []) {
            return 0;
        }
        $DB->delete(self::getTable(), [
            'plugin_mailtoticket_mailboxes_id' => $mailboxes_id,
            'message_key'                      => $keys,
            'status'                           => self::STATUS_DISCARDED,
        ]);
        return $DB->affectedRows();
    }

    /** @return string[] apenas chaves sha1 válidas */
    private static function validKeys(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, static fn ($k) => is_string($k) && preg_match('/^[0-9a-f]{40}$/', $k) === 1)));
    }
}
