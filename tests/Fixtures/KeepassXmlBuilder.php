<?php

namespace Tests\Fixtures;

/**
 * Builds small, synthetic KeePass 2.x / KeePassXC XML exports for tests.
 *
 * Groups and entries are composed as XML strings and wrapped by `build()`,
 * which adds the `Meta` block with the binary pool and recycle bin marker.
 */
final class KeepassXmlBuilder
{
    /** @var array<string, array{content: string, compressed: bool}> */
    private array $binaries = [];

    private ?string $recycleBinUuid = null;

    /**
     * A stable, KeePass-style base64 UUID derived from a seed.
     */
    public static function uuid(string $seed): string
    {
        return base64_encode(md5($seed, true));
    }

    /**
     * Add a pooled binary from its plaintext bytes.
     */
    public function binary(string $id, string $bytes, bool $compressed = false): self
    {
        return $this->rawBinary($id, base64_encode($compressed ? (string) gzencode($bytes) : $bytes), $compressed);
    }

    /**
     * Add a pooled binary with literal (possibly invalid) content.
     */
    public function rawBinary(string $id, string $content, bool $compressed = false): self
    {
        $this->binaries[$id] = ['content' => $content, 'compressed' => $compressed];

        return $this;
    }

    public function recycleBin(string $uuid): self
    {
        $this->recycleBinUuid = $uuid;

        return $this;
    }

    /**
     * An `<Entry>`. Fields are KeePass string keys (`UserName`, `Password`,
     * `URL`, `Notes`, or any custom key).
     *
     * @param  array<string, string>  $fields
     * @param  array{uuid?: string, protect?: list<string>, stream_protected?: list<string>, tags?: string, expires?: string, binaries?: array<string, string|null>, inline_binaries?: array<string, string>, history?: int}  $options
     */
    public function entry(string $title, array $fields = [], array $options = []): string
    {
        $strings = $this->string('Title', $title);
        foreach ($fields as $key => $value) {
            $attributes = '';
            if (in_array($key, $options['protect'] ?? [], true)) {
                $attributes = ' ProtectInMemory="True"';
            }
            if (in_array($key, $options['stream_protected'] ?? [], true)) {
                $attributes = ' Protected="True"';
            }
            $strings .= $this->string($key, $value, $attributes);
        }

        $binaries = '';
        foreach ($options['binaries'] ?? [] as $filename => $ref) {
            $value = $ref === null ? '<Value/>' : '<Value Ref="'.$ref.'"/>';
            $binaries .= '<Binary><Key>'.$this->escape($filename).'</Key>'.$value.'</Binary>';
        }
        foreach ($options['inline_binaries'] ?? [] as $filename => $bytes) {
            $binaries .= '<Binary><Key>'.$this->escape($filename).'</Key><Value Compressed="True">'.base64_encode((string) gzencode($bytes)).'</Value></Binary>';
        }

        $history = '';
        if (($options['history'] ?? 0) > 0) {
            $history = '<History>';
            for ($i = 0; $i < $options['history']; $i++) {
                $history .= '<Entry><UUID>'.self::uuid($title.'-history-'.$i).'</UUID>'.$this->string('Title', $title.' (old)').'</Entry>';
            }
            $history .= '</History>';
        }

        $times = '<Times><CreationTime>2024-01-01T00:00:00Z</CreationTime>';
        $times .= isset($options['expires'])
            ? '<ExpiryTime>'.$options['expires'].'</ExpiryTime><Expires>True</Expires>'
            : '<ExpiryTime>2024-01-01T00:00:00Z</ExpiryTime><Expires>False</Expires>';
        $times .= '</Times>';

        return '<Entry>'
            .'<UUID>'.($options['uuid'] ?? self::uuid('entry-'.$title)).'</UUID>'
            .'<IconID>0</IconID>'
            .'<Tags>'.$this->escape($options['tags'] ?? '').'</Tags>'
            .$times
            .$strings
            .$binaries
            .'<AutoType><Enabled>True</Enabled></AutoType>'
            .$history
            .'</Entry>';
    }

    /**
     * A `<Group>` holding entries and subgroups.
     *
     * @param  list<string>  $children
     */
    public function group(string $name, array $children = [], ?string $uuid = null): string
    {
        return '<Group>'
            .'<UUID>'.($uuid ?? self::uuid('group-'.$name)).'</UUID>'
            .'<Name>'.$this->escape($name).'</Name>'
            .'<IconID>48</IconID>'
            .implode('', $children)
            .'</Group>';
    }

    /**
     * Wrap the root group's children in a complete export.
     *
     * @param  list<string>  $children
     */
    public function build(array $children, string $rootName = 'Database'): string
    {
        $binaries = '';
        foreach ($this->binaries as $id => $binary) {
            $compressed = $binary['compressed'] ? ' Compressed="True"' : '';
            $binaries .= '<Binary ID="'.$id.'"'.$compressed.'>'.$binary['content'].'</Binary>';
        }

        $meta = '<Meta>'
            .'<Generator>KeePassXC</Generator>'
            .'<DatabaseName>'.$this->escape($rootName).'</DatabaseName>'
            .'<RecycleBinEnabled>True</RecycleBinEnabled>'
            .'<RecycleBinUUID>'.($this->recycleBinUuid ?? 'AAAAAAAAAAAAAAAAAAAAAA==').'</RecycleBinUUID>'
            .($binaries === '' ? '' : '<Binaries>'.$binaries.'</Binaries>')
            .'</Meta>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.PHP_EOL
            .'<KeePassFile>'
            .$meta
            .'<Root>'.$this->group($rootName, $children, self::uuid('root')).'<DeletedObjects/></Root>'
            .'</KeePassFile>';
    }

    private function string(string $key, string $value, string $attributes = ''): string
    {
        return '<String><Key>'.$this->escape($key).'</Key><Value'.$attributes.'>'.$this->escape($value).'</Value></String>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
