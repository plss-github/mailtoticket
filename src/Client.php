<?php

namespace GlpiPlugin\Mailtoticket;

use GLPIKey;
use Laminas\Mail\Header\AbstractAddressList;
use Laminas\Mail\Protocol\Imap as ImapProtocol;
use Laminas\Mail\Storage;
use Laminas\Mail\Storage\Imap as ImapStorage;
use Laminas\Mail\Storage\AbstractStorage;
use Laminas\Mail\Storage\Message;
use MailCollector;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use Toolbox;

/**
 * Leitura (somente leitura) de uma caixa de e-mail usando as mesmas
 * bibliotecas do coletor nativo. Não marca como lido, não move e não apaga.
 */
final class Client
{
    private const CHUNK      = 50;  // cabeçalhos por requisição IMAP
    private const SCAN_LIMIT = 500; // máximo de e-mails varridos por tela
    private const CACHE_TTL  = 60;  // segundos: lista de cabeçalhos em cache (o estado chamado/descartado é sempre lido do banco)
    private ?AbstractStorage $storage = null;
    /** @var int[] */
    private array $unseen_to_restore = [];
    private int $box_total = 0;

    public function __construct(private readonly Mailbox $mailbox)
    {
    }

    private static function cache(): ?\Psr\SimpleCache\CacheInterface
    {
        global $GLPI_CACHE;

        return $GLPI_CACHE instanceof \Psr\SimpleCache\CacheInterface ? $GLPI_CACHE : null;
    }

    private static function cacheVersion(int $mailbox_id): string
    {
        try {
            return (string) (self::cache()?->get('mailtoticket_ver_' . $mailbox_id) ?? '0');
        } catch (Throwable) {
            return '0';
        }
    }

    /** Invalida a lista em cache desta caixa (botão Atualizar, edição da caixa). */
    public static function bustCache(int $mailbox_id): void
    {
        try {
            self::cache()?->set('mailtoticket_ver_' . $mailbox_id, (string) microtime(true), 86400 * 30);
        } catch (Throwable) {
            // sem cache: nada a invalidar
        }
    }

    private function cacheKey(string $kind, array $parts): string
    {
        $id = (int) $this->mailbox->getID();
        return 'mailtoticket_' . $kind . '_' . $id . '_' . md5(self::cacheVersion($id) . '|' . json_encode($parts));
    }

    /** @return mixed valor em cache, ou o resultado de $load() (que é gravado) */
    private function remember(string $kind, array $parts, callable $load): mixed
    {
        $cache = self::cache();
        $key   = $this->cacheKey($kind, $parts);
        try {
            $hit = $cache?->get($key);
            if (is_array($hit)) {
                return $hit;
            }
        } catch (Throwable) {
            $cache = null;
        }
        $value = $load();
        try {
            $cache?->set($key, $value, self::CACHE_TTL);
        } catch (Throwable) {
            // cache indisponível: segue sem
        }
        return $value;
    }

    public function storage(): AbstractStorage
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        $f      = $this->mailbox->fields;
        $config = Toolbox::parseMailServerConnectString($f['host']);

        $params = [
            'host'     => $config['address'],
            'user'     => $f['login'],
            'password' => (new GLPIKey())->decrypt($f['passwd'] ?? ''),
            'port'     => $config['port'],
        ];
        if (!empty($config['ssl'])) {
            $params['ssl'] = 'SSL';
        }
        if (!empty($config['tls'])) {
            $params['ssl'] = 'TLS';
        }
        if (!empty($config['mailbox'])) {
            $params['folder'] = mb_convert_encoding($config['mailbox'], 'UTF7-IMAP', 'UTF-8');
        }
        if (($config['validate-cert'] ?? true) === false) {
            $params['novalidatecert'] = true;
        }

        $storage = Toolbox::getMailServerStorageInstance($config['type'], $params);
        if ($storage === null) {
            throw new RuntimeException(sprintf(__('Tipo de servidor não suportado: %s', 'mailtoticket'), $config['type']));
        }

        return $this->storage = $storage;
    }

    /**
     * Testa a conexão com os dados salvos.
     *
     * @return array{ok:bool,message:string}
     */
    public static function test(Mailbox $mailbox): array
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;
            return true;
        });
        try {
            $client  = new self($mailbox);
            $storage = $client->storage();
            $total   = $storage->countMessages();
            Mailbox::recordCheck((int) $mailbox->getID(), null);
            return ['ok' => true, 'message' => sprintf(
                __('Conexão estabelecida com sucesso. %d e-mail(s) na pasta.', 'mailtoticket'),
                $total
            )];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($warnings !== []) {
                $msg .= ' (' . implode('; ', array_unique($warnings)) . ')';
            }
            if (str_contains((string) $mailbox->fields['host'], 'imap-oauth')) {
                $msg .= ' — ' . __('Verifique se a conta ainda está autorizada em Configurar > OAuth IMAP e se o plugin está ativo.', 'mailtoticket');
            }
            Mailbox::recordCheck((int) $mailbox->getID(), $msg);
            return ['ok' => false, 'message' => __('Falha na conexão: ', 'mailtoticket') . $msg];
        } finally {
            restore_error_handler();
        }
    }

    /** E-mail do remetente (From), em minúsculas. */
    public static function senderEmail(Message $msg): ?string
    {
        try {
            $h = $msg->getHeader('from');
            if ($h instanceof AbstractAddressList) {
                foreach ($h->getAddressList() as $a) {
                    if ($a->getEmail()) {
                        return mb_strtolower($a->getEmail());
                    }
                }
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function imapProtocol(): ?ImapProtocol
    {
        $storage = $this->storage();
        if (!$storage instanceof ImapStorage) {
            return null;
        }
        return (fn () => $this->protocol)->call($storage);
    }

    /**
     * Números de sequência (mais novos primeiro) que atendem aos filtros do servidor.
     * Em IMAP a busca (texto, datas, não lidos) roda no servidor; se falhar ou em POP,
     * devolve todos e pede filtragem local.
     *
     * @param array{q?:string,since?:string,until?:string,unseen?:bool} $f
     * @return array{0:int[],1:bool} [ids, filtrar_localmente]
     */
    private function matchingIds(array $f): array
    {
        $total    = $this->storage()->countMessages();
        $this->box_total = $total;
        $has_filt = ($f['q'] ?? '') !== '' || ($f['since'] ?? '') !== '' || ($f['until'] ?? '') !== '' || !empty($f['unseen']);
        $all      = $total > 0 ? range($total, 1) : [];

        if (!$has_filt) {
            return [$all, false];
        }
        $proto = $this->imapProtocol();
        if ($proto === null) {
            return [$all, true];
        }

        // O texto é filtrado localmente (trecho do assunto/remetente, sem acento nem maiúscula):
        // o SEARCH do servidor só acha palavras inteiras em vários provedores (Gmail, Office 365).
        $local = trim((string) ($f['q'] ?? '')) !== '';
        try {
            $c = [];
            if (!empty($f['since']) && ($t = strtotime($f['since'])) !== false) {
                array_push($c, 'SINCE', gmdate('j-M-Y', $t));
            }
            if (!empty($f['until']) && ($t = strtotime($f['until'])) !== false) {
                array_push($c, 'BEFORE', gmdate('j-M-Y', $t + 86400));
            }
            if (!empty($f['unseen'])) {
                $c[] = 'UNSEEN';
            }
            if ($c === []) {
                return [$all, $local];
            }
            $res = $proto->search($c);
            if (!is_array($res)) {
                throw new RuntimeException('Busca recusada pelo servidor'); // cai no filtro local
            }
            $ids = array_map('intval', $res);
            rsort($ids);
            return [$ids, $local];
        } catch (Throwable) {
            return [$all, true];
        }
    }

    /**
     * Cabeçalhos de vários e-mails de uma vez (1 requisição IMAP por lote).
     *
     * @param int[] $seqs
     * @return array<int,array<string,mixed>> seq => item
     */
    private function fetchBatch(array $seqs): array
    {
        $out   = [];
        $proto = $this->imapProtocol();

        if ($proto !== null) {
            $data = $proto->fetch(['UID', 'FLAGS', 'RFC822.HEADER'], $seqs);
            foreach ($seqs as $n) {
                $d = $data[$n] ?? null;
                if (!is_array($d) || !isset($d['RFC822.HEADER'])) {
                    continue;
                }
                try {
                    $msg = new Message(['headers' => $d['RFC822.HEADER']]);
                    $out[$n] = $this->summarize($msg, (string) ($d['UID'] ?? ''), !in_array('\\Seen', (array) ($d['FLAGS'] ?? []), true));
                } catch (Throwable) {
                    continue;
                }
            }
            return $out;
        }

        $storage = $this->storage();
        foreach ($seqs as $n) {
            try {
                $msg = $storage->getMessage($n);
                $out[$n] = $this->summarize($msg, (string) $storage->getUniqueId($n), !$msg->hasFlag(Storage::FLAG_SEEN));
            } catch (Throwable) {
                continue;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function summarize(Message $msg, string $uid, bool $unseen): array
    {
        return [
            'uid'     => $uid,
            'key'     => self::messageKey($msg),
            'subject' => self::subject($msg),
            'from'    => self::from($msg),
            'date'    => self::date($msg),
            'unseen'  => $unseen,
        ];
    }

    /** Minúsculas e sem acentos, para comparar textos. */
    private static function fold(string $text): string
    {
        $text = trim($text);
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $text);
            if (is_string($t)) {
                return $t;
            }
        }
        return mb_strtolower($text);
    }

    private function matchesLocal(array $it, array $f): bool
    {
        $q = self::fold((string) ($f['q'] ?? ''));
        if ($q !== '' && !str_contains(self::fold($it['subject'] . ' ' . $it['from']), $q)) {
            return false;
        }
        if (!empty($f['unseen']) && !$it['unseen']) {
            return false;
        }
        if (!empty($f['since']) && $it['date'] !== null && $it['date'] < strtotime($f['since'])) {
            return false;
        }
        if (!empty($f['until']) && $it['date'] !== null && $it['date'] >= strtotime($f['until']) + 86400) {
            return false;
        }
        return true;
    }

    /**
     * Lista os e-mails (mais novos primeiro) já aplicando filtros e o status
     * (pending = sem chamado, linked = com chamado, discarded = descartados, all). Varre em lotes e para
     * quando completa a página ou ao atingir o limite de varredura.
     *
     * @param array{q?:string,since?:string,until?:string,unseen?:bool} $f
     * @return array{items:array<int,array<string,mixed>>,total:int,next:?int,box_total:int}
     */
    public function scan(array $f, string $status, int $start, int $per_page): array
    {
        [$ids, $local, $this->box_total] = $this->remember('ids', [$f], fn () => [...$this->matchingIds($f), $this->box_total]);
        $total   = count($ids);
        $mb_id   = (int) $this->mailbox->getID();
        $items   = [];
        $pos     = max(0, $start);
        $scanned = 0;
        $next    = null;

        while ($pos < $total && $scanned < self::SCAN_LIMIT) {
            $chunk = array_slice($ids, $pos, self::CHUNK);
            $batch = $this->remember('hdr', [$f, $chunk], fn () => $this->fetchBatch($chunk));
            $states = MailTicket::getStates($mb_id, array_column($batch, 'key'));

            foreach ($chunk as $i => $seq) {
                if (!isset($batch[$seq])) {
                    continue;
                }
                $it = $batch[$seq];
                if ($local && !$this->matchesLocal($it, $f)) {
                    continue;
                }
                $st               = $states[$it['key']] ?? null;
                $it['followup']   = $st !== null && $st['status'] === MailTicket::STATUS_FOLLOWUP;
                $it['tickets_id'] = ($st !== null && in_array($st['status'], [MailTicket::STATUS_TICKET, MailTicket::STATUS_FOLLOWUP], true)) ? $st['tickets_id'] : null;
                $it['discarded']  = $st !== null && $st['status'] === MailTicket::STATUS_DISCARDED;
                $it_state         = $it['tickets_id'] !== null ? 'linked' : ($it['discarded'] ? 'discarded' : 'pending');
                if ($status !== 'all' && $status !== $it_state) {
                    continue;
                }
                $items[] = $it;
                if (count($items) >= $per_page) {
                    $next = $pos + $i + 1;
                    break 2;
                }
            }
            $pos     += count($chunk);
            $scanned += count($chunk);
        }

        if ($next === null && $pos < $total) {
            $next = $pos; // limite de varredura: há mais para continuar
        }
        if ($next !== null && $next >= $total) {
            $next = null;
        }

        return ['items' => $items, 'total' => $total, 'next' => $next, 'box_total' => $this->box_total];
    }

    /** Desfaz o "lido" causado pela leitura dos e-mails abertos nesta requisição. */
    public function restoreUnseen(): void
    {
        $proto = $this->imapProtocol();
        foreach ($this->unseen_to_restore as $num) {
            try {
                $proto?->store(['\\Seen'], $num, null, '-', true);
            } catch (Throwable) {
                // melhor esforço
            }
        }
        $this->unseen_to_restore = [];
    }

    public function get(string $uid): Message
    {
        $storage = $this->storage();
        $num     = $storage->getNumberByUniqueId($uid);
        $msg     = $storage->getMessage($num);

        // Ler o corpo (RFC822.TEXT) marca o e-mail como lido no servidor. Para manter a caixa
        // intacta (ex.: coletor nativo "só não lidos"), restoreUnseen() devolve o estado.
        if ($this->imapProtocol() !== null && !$msg->hasFlag(Storage::FLAG_SEEN)) {
            $this->unseen_to_restore[] = $num;
        }

        return $msg;
    }

    /**
     * "Coletor" em memória (sem gravar nada) que reaproveita o parser do core:
     * getBody(), getAttached(), buildTicket()...
     */
    public function collectorShell(): MailCollector
    {
        $f  = $this->mailbox->fields;
        $mc = new MailCollector();
        $mc->fields = [
            'id'                     => 0,
            'name'                   => $f['login'] ?? '',
            'host'                   => $f['host'],
            'login'                  => $f['login'],
            'filesize_max'           => (int) $f['filesize_max'],
            'is_active'              => 1,
            'use_mail_date'          => 0,
            'requester_field'        => 0,
            'add_to_to_observer'     => (int) $f['add_to_to_observer'],
            'add_cc_to_observer'     => (int) $f['add_cc_to_observer'],
            'collect_only_unread'    => 0,
            'create_user_from_email' => 0, // regra: nunca criar usuário a partir do e-mail
            'errors'                 => 0,
        ];
        return $mc;
    }

    public static function messageKey(Message $msg): string
    {
        try {
            $id = trim($msg->getHeader('message-id')->getFieldValue());
        } catch (Throwable) {
            $id = '';
        }
        if ($id === '') {
            $id = 'MISSING_' . $msg->getHeaders()->toString();
        }
        return sha1($id);
    }

    /** sha1 do assunto sem prefixos de resposta/encaminhamento (Re:, RES:, Fwd:, ...), para achar a mesma conversa. */
    public static function subjectKey(string $subject): ?string
    {
        $s = mb_strtolower(trim($subject));
        do {
            $before = $s;
            $s = preg_replace('/^\s*(re|res|rv|fw|fwd|enc|tr|aw|antw)\s*(\[\d+\])?\s*:\s*/u', '', $s) ?? $s;
        } while ($s !== $before);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return mb_strlen($s) >= 4 ? sha1($s) : null; // assuntos curtos demais dariam falso positivo
    }

    /**
     * Chaves (mesmo formato de messageKey) dos e-mails citados em In-Reply-To/References.
     *
     * @return string[]
     */
    public static function referencedKeys(Message $msg): array
    {
        $keys = [];
        foreach (['in-reply-to', 'references'] as $h) {
            try {
                $value = $msg->getHeader($h)->getFieldValue();
            } catch (Throwable) {
                continue;
            }
            if (preg_match_all('/<[^<>\s]+>/', (string) $value, $m)) {
                foreach ($m[0] as $id) {
                    $keys[] = sha1($id);
                }
            }
        }
        return array_values(array_unique($keys));
    }

    public static function subject(Message $msg): string
    {
        try {
            return trim($msg->getHeader('subject')->getFieldValue());
        } catch (Throwable) {
            return '';
        }
    }

    /** @return string "Nome <email>" */
    public static function from(Message $msg): string
    {
        return self::addresses($msg, 'from');
    }

    public static function addresses(Message $msg, string $header): string
    {
        try {
            $h = $msg->getHeader($header);
            if (!$h instanceof AbstractAddressList) {
                return '';
            }
            $out = [];
            foreach ($h->getAddressList() as $a) {
                $name  = trim((string) $a->getName());
                $email = (string) $a->getEmail();
                $out[] = $name !== '' ? "$name <$email>" : $email;
            }
            return implode(', ', $out);
        } catch (Throwable) {
            return '';
        }
    }

    public static function date(Message $msg): ?int
    {
        try {
            $ts = strtotime($msg->getHeader('date', 'string'));
            return $ts === false ? null : $ts;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return string[] nomes dos anexos */
    public static function attachmentNames(Message $msg): array
    {
        $names = [];
        try {
            if (!$msg->isMultipart()) {
                return [];
            }
            foreach (new RecursiveIteratorIterator($msg) as $part) {
                try {
                    $disp = $part->getHeader('content-disposition');
                    $name = method_exists($disp, 'getParameter') ? $disp->getParameter('filename') : null;
                    if ($name) {
                        $names[] = (string) $name;
                    }
                } catch (Throwable) {
                    // parte sem content-disposition
                }
            }
        } catch (Throwable) {
        }
        return $names;
    }
}
