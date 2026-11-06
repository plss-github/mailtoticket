<?php

namespace GlpiPlugin\Mailtoticket;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use GLPIKey;
use Session;
use Toolbox;

/**
 * Caixa de e-mail (IMAP/POP/IMAP-OAuth) cadastrada no plugin, por entidade.
 * Independente do coletor nativo do GLPI.
 */
class Mailbox extends CommonDBTM
{
    public static $rightname = Rights::NAME;
    public $dohistory        = true;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_mailtoticket_mailboxes';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Caixa de e-mail', 'Caixas de e-mail', $nb, 'mailtoticket');
    }

    public static function getIcon()
    {
        return 'ti ti-mail';
    }

    public function isEntityAssign()
    {
        return true;
    }

    public function maybeRecursive()
    {
        return true;
    }

    // O cadastro de caixas é "gerenciar" (UPDATE); ler e-mails (READ) não dá acesso ao cadastro.
    public static function canView(): bool
    {
        return Rights::canManage();
    }

    public static function canCreate(): bool
    {
        return Rights::canManage();
    }

    public static function canUpdate(): bool
    {
        return Rights::canManage();
    }

    public static function canDelete(): bool
    {
        return Rights::canManage();
    }

    public static function canPurge(): bool
    {
        return Rights::canManage();
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input, true);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input, false);
    }

    private function prepareInput(array $input, bool $is_add)
    {
        // Regra: o plugin nunca cria usuários a partir do e-mail do remetente.
        $input['create_user_from_email'] = 0;

        foreach (['itilcategories_id', 'tickettype', 'urgency'] as $f) {
            if (array_key_exists($f, $input)) {
                $input[$f] = max(0, (int) $input[$f]);
            }
        }
        if (isset($input['tickettype']) && !in_array($input['tickettype'], [0, \Ticket::INCIDENT_TYPE, \Ticket::DEMAND_TYPE], true)) {
            $input['tickettype'] = 0;
        }
        if (isset($input['urgency']) && $input['urgency'] > 5) {
            $input['urgency'] = 0;
        }

        // Monta a string de conexão a partir dos campos de servidor (igual ao coletor do GLPI)
        if (array_key_exists('mail_server', $input)) {
            $input['host'] = Toolbox::constructMailServerConfig($input);
        }
        if ($is_add && trim((string) ($input['host'] ?? '')) === '') {
            Session::addMessageAfterRedirect(__('Informe o servidor.', 'mailtoticket'), false, ERROR);
            return false;
        }

        if (array_key_exists('passwd', $input)) {
            if ($input['passwd'] === '' || $input['passwd'] === null) {
                unset($input['passwd']); // em branco = mantém a senha atual
            } else {
                $input['passwd'] = (new GLPIKey())->encrypt($input['passwd']);
            }
        }

        return $input;
    }

    public function post_updateItem($history = true)
    {
        Client::bustCache((int) $this->getID()); // login/host/pasta podem ter mudado
    }

    /**
     * Padrões do chamado para esta caixa: o que a caixa define (0 = não definido)
     * tem prioridade sobre a configuração da entidade.
     *
     * @return array{tickettype:int,urgency:int,itilcategories_id:int}
     */
    public function getTicketDefaults(): array
    {
        $cfg = Configuration::getForEntity((int) $this->fields['entities_id']);
        $out = [];
        foreach (['tickettype', 'urgency', 'itilcategories_id'] as $f) {
            $own    = (int) ($this->fields[$f] ?? 0);
            $out[$f] = $own > 0 ? $own : (int) $cfg[$f];
        }
        return $out;
    }

    /** Guarda o resultado da última conexão (null = sucesso) sem alterar date_mod nem o histórico. */
    public static function recordCheck(int $id, ?string $error): void
    {
        global $DB;

        $DB->update(self::getTable(), [
            'last_check' => date('Y-m-d H:i:s'),
            'last_error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ], ['id' => $id]);
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([MailTicket::class]);
    }

    /** Protocolos de servidor disponíveis (imap, pop e os de plugins, ex.: imap-oauth-N do OAuth IMAP). */
    public static function getProtocolChoices(): array
    {
        $choices = [];
        foreach (Toolbox::getMailServerProtocols(allow_plugins_protocols: true) as $key => $protocol) {
            $choices['/' . $key] = $protocol['label'];
        }
        return $choices;
    }

    /**
     * Contas autorizadas do plugin OAuth IMAP (se instalado): [['app' => id, 'email' => ..., 'app_name' => ...]].
     */
    public static function getOauthAccounts(): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_oauthimap_authorizations') || !$DB->tableExists('glpi_plugin_oauthimap_applications')) {
            return [];
        }
        $out = [];
        foreach (
            $DB->request([
                'SELECT'    => ['a.plugin_oauthimap_applications_id AS app', 'a.email', 'p.name AS app_name'],
                'FROM'      => 'glpi_plugin_oauthimap_authorizations AS a',
                'LEFT JOIN' => ['glpi_plugin_oauthimap_applications AS p' => ['FKEY' => ['a' => 'plugin_oauthimap_applications_id', 'p' => 'id']]],
                'ORDER'     => ['p.name', 'a.email'],
            ]) as $row
        ) {
            $out[] = ['app' => (int) $row['app'], 'email' => $row['email'], 'app_name' => $row['app_name']];
        }
        return $out;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $tab[] = ['id' => '1', 'table' => self::getTable(), 'field' => 'name', 'name' => __('Nome'), 'datatype' => 'itemlink', 'massiveaction' => false];
        $tab[] = ['id' => '2', 'table' => self::getTable(), 'field' => 'host', 'name' => __('Servidor', 'mailtoticket'), 'datatype' => 'string'];
        $tab[] = ['id' => '3', 'table' => self::getTable(), 'field' => 'login', 'name' => __('Login'), 'datatype' => 'string'];
        $tab[] = ['id' => '4', 'table' => self::getTable(), 'field' => 'is_active', 'name' => __('Ativa', 'mailtoticket'), 'datatype' => 'bool'];
        $tab[] = ['id' => '5', 'table' => self::getTable(), 'field' => 'last_check', 'name' => __('Última conexão', 'mailtoticket'), 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '6', 'table' => self::getTable(), 'field' => 'last_error', 'name' => __('Último erro', 'mailtoticket'), 'datatype' => 'text', 'massiveaction' => false];
        $tab[] = ['id' => '19', 'table' => self::getTable(), 'field' => 'date_mod', 'name' => __('Última atualização'), 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '30', 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false];
        $tab[] = ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entidade'), 'datatype' => 'dropdown'];
        $tab[] = ['id' => '86', 'table' => self::getTable(), 'field' => 'is_recursive', 'name' => __('Child entities'), 'datatype' => 'bool'];

        return $tab;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $host = (string) ($this->fields['host'] ?? '');
        $connect_opts = $host !== ''
            ? Toolbox::parseMailServerConnectString($host)
            : ['address' => '', 'port' => '', 'type' => 'imap', 'ssl' => true, 'tls' => '', 'validate-cert' => true,
               'mailbox' => 'INBOX', 'norsh' => false, 'secure' => false, 'debug' => false];

        TemplateRenderer::getInstance()->display('@mailtoticket/mailbox_form.html.twig', [
            'item'             => $this,
            'params'           => $options,
            'connect_opts'     => $connect_opts,
            'connect_string'   => $host,
            'protocol_choices' => self::getProtocolChoices(),
            'oauth_accounts'   => self::getOauthAccounts(),
            'test_url'         => PLUGIN_MAILTOTICKET_WEBDIR . '/front/mailbox.test.php?id=' . (int) $ID,
            'inbox_url'        => PLUGIN_MAILTOTICKET_WEBDIR . '/front/messages.php?mailbox=' . (int) $ID,
            'type_choices'     => [0 => \Dropdown::EMPTY_VALUE, \Ticket::INCIDENT_TYPE => \Ticket::getTicketTypeName(\Ticket::INCIDENT_TYPE), \Ticket::DEMAND_TYPE => \Ticket::getTicketTypeName(\Ticket::DEMAND_TYPE)],
            'urgency_choices'  => array_replace([0 => \Dropdown::EMPTY_VALUE], array_combine(range(1, 5), array_map(static fn ($i) => \Ticket::getUrgencyName($i), range(1, 5)))),
        ]);
        return true;
    }
}
