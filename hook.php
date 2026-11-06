<?php

use GlpiPlugin\Mailtoticket\Rights;

function plugin_mailtoticket_install()
{
    global $DB;

    $migration = new Migration(PLUGIN_MAILTOTICKET_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // --- Caixas de e-mail (por entidade) ---------------------------------
    $table = 'glpi_plugin_mailtoticket_mailboxes';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `host` varchar(255) DEFAULT NULL,
            `login` varchar(255) DEFAULT NULL,
            `passwd` varchar(255) DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT '1',
            `entities_id` int {$sign} NOT NULL DEFAULT '0',
            `is_recursive` tinyint NOT NULL DEFAULT '0',
            `filesize_max` int NOT NULL DEFAULT '2097152',
            `create_user_from_email` tinyint NOT NULL DEFAULT '0',
            `add_to_to_observer` tinyint NOT NULL DEFAULT '0',
            `add_cc_to_observer` tinyint NOT NULL DEFAULT '0',
            `itilcategories_id` int {$sign} NOT NULL DEFAULT '0',
            `tickettype` tinyint NOT NULL DEFAULT '0',
            `urgency` tinyint NOT NULL DEFAULT '0',
            `comment` text,
            `last_check` timestamp NULL DEFAULT NULL,
            `last_error` text,
            `date_mod` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `is_active` (`is_active`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `date_mod` (`date_mod`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    } elseif (!$DB->fieldExists($table, 'is_recursive')) {
        $migration->addField($table, 'is_recursive', 'bool', ['after' => 'entities_id']);
        $migration->addKey($table, 'is_recursive');
    }

    // --- Vínculo e-mail -> chamado / descartado ---------------------------
    $table = 'glpi_plugin_mailtoticket_tickets';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_mailtoticket_mailboxes_id` int {$sign} NOT NULL DEFAULT '0',
            `message_key` char(40) NOT NULL,
            `status` tinyint NOT NULL DEFAULT '1' COMMENT '1 = chamado aberto, 2 = descartado',
            `tickets_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `mailbox_message` (`plugin_mailtoticket_mailboxes_id`, `message_key`),
            KEY `tickets_id` (`tickets_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    } elseif (!$DB->fieldExists($table, 'status')) {
        $migration->addField($table, 'status', 'tinyint', ['value' => 1, 'after' => 'message_key']);
        $migration->addKey($table, 'status');
    }

    // --- Configuração por entidade (-2 = herdar da entidade pai) ----------
    $table = 'glpi_plugin_mailtoticket_configs';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int {$sign} NOT NULL DEFAULT '0',
            `per_page` int NOT NULL DEFAULT '-2',
            `tickettype` int NOT NULL DEFAULT '-2',
            `urgency` int NOT NULL DEFAULT '-2',
            `itilcategories_id` int NOT NULL DEFAULT '-2',
            PRIMARY KEY (`id`),
            UNIQUE KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");

        // Entidade raiz: valores da configuração global anterior (se existir)
        $old = Config::getConfigurationValues('plugin:mailtoticket');
        $DB->insert($table, [
            'entities_id'       => 0,
            'per_page'          => min(100, max(5, (int) ($old['per_page'] ?? 25))),
            'tickettype'        => (int) ($old['tickettype'] ?? 0),
            'urgency'           => (int) ($old['urgency'] ?? 0),
            'itilcategories_id' => (int) ($old['itilcategories_id'] ?? 0),
        ]);
    }

    $migration->executeMigration();

    // Assunto normalizado do e-mail (reconhecer novas mensagens da mesma conversa/assunto)
    if (!$DB->fieldExists('glpi_plugin_mailtoticket_tickets', 'subject_key')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_mailtoticket_tickets` ADD `subject_key` char(40) DEFAULT NULL AFTER `message_key`, ADD KEY `subject_key` (`subject_key`)");
    }

    // Regras por caixa (0 = usar o padrão da entidade) e último resultado da conexão
    $mb = 'glpi_plugin_mailtoticket_mailboxes';
    foreach (
        [
            'itilcategories_id' => "`itilcategories_id` int {$sign} NOT NULL DEFAULT '0' AFTER `add_cc_to_observer`",
            'tickettype'        => "`tickettype` tinyint NOT NULL DEFAULT '0' AFTER `itilcategories_id`",
            'urgency'           => "`urgency` tinyint NOT NULL DEFAULT '0' AFTER `tickettype`",
            'last_check'        => "`last_check` timestamp NULL DEFAULT NULL AFTER `comment`",
            'last_error'        => "`last_error` text AFTER `last_check`",
        ] as $field => $ddl
    ) {
        if (!$DB->fieldExists($mb, $field)) {
            $DB->doQuery("ALTER TABLE `$mb` ADD $ddl");
        }
    }

    // Vínculos anteriores à coluna "status" são chamados abertos (1); depois fixa NOT NULL DEFAULT 1
    $links = 'glpi_plugin_mailtoticket_tickets';
    $DB->doQuery("UPDATE `$links` SET `status` = 1 WHERE `status` IS NULL");
    $DB->doQuery("ALTER TABLE `$links` MODIFY `status` tinyint NOT NULL DEFAULT 1");

    // --- Permissão própria ------------------------------------------------
    if (countElementsInTable('glpi_profilerights', ['name' => Rights::NAME]) === 0) {
        ProfileRight::addProfileRights([Rights::NAME]);
    }
    // Primeira instalação: quem já administra a configuração do GLPI recebe todos os direitos
    $all = READ | UPDATE | CREATE | DELETE;
    foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $row) {
        if (((int) $row['rights'] & UPDATE) === 0) {
            continue;
        }
        $DB->update(
            'glpi_profilerights',
            ['rights' => $all],
            ['profiles_id' => $row['profiles_id'], 'name' => Rights::NAME, 'rights' => 0]
        );
    }

    return true;
}

function plugin_mailtoticket_uninstall()
{
    global $DB;

    foreach (
        [
            'glpi_plugin_mailtoticket_tickets',
            'glpi_plugin_mailtoticket_configs',
            'glpi_plugin_mailtoticket_mailboxes',
        ] as $table
    ) {
        if ($DB->tableExists($table)) {
            $DB->dropTable($table);
        }
    }

    ProfileRight::deleteProfileRights([Rights::NAME]);
    $DB->delete('glpi_configs', ['context' => 'plugin:mailtoticket']);

    return true;
}
