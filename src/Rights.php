<?php

namespace GlpiPlugin\Mailtoticket;

use Session;

/**
 * Direito próprio do plugin (aba "E-mail para chamado" no Perfil):
 *   READ   - ver as caixas e os e-mails
 *   CREATE - abrir chamado a partir de um e-mail
 *   DELETE - descartar / restaurar e-mails
 *   UPDATE - gerenciar caixas e configuração
 */
final class Rights
{
    public const NAME = 'plugin_mailtoticket';

    public static function canView(): bool
    {
        return Session::haveRight(self::NAME, READ);
    }

    public static function canOpenTicket(): bool
    {
        return Session::haveRight(self::NAME, CREATE) && Session::haveRight('ticket', CREATE);
    }

    public static function canDiscard(): bool
    {
        return Session::haveRight(self::NAME, DELETE);
    }

    public static function canManage(): bool
    {
        return Session::haveRight(self::NAME, UPDATE);
    }

    /** Pode usar a caixa (permissão + acesso à entidade dela) */
    public static function canUseMailbox(Mailbox $mailbox): bool
    {
        return self::canView()
            && Session::haveAccessToEntity((int) $mailbox->fields['entities_id'], (bool) $mailbox->fields['is_recursive']);
    }
}
