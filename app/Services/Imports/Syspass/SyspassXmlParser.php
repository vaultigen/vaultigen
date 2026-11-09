<?php

namespace App\Services\Imports\Syspass;

use App\Services\Imports\SafeXml;
use DOMDocument;
use DOMElement;

/**
 * Parses a decrypted sysPass export into a plain intermediate representation.
 *
 * Every account keeps its raw `<key>` and `<pass>` strings; the runner decrypts
 * the passwords with the master password later. No value is interpreted or
 * guessed: empty category or client references are preserved as empty so the
 * planner can place the entry at the vault root.
 */
final class SyspassXmlParser
{
    /**
     * @return array{
     *     meta: array{generator: string|null, version: string|null, user: string|null},
     *     categories: list<array<string, string|null>>,
     *     clients: list<array<string, string|null>>,
     *     tags: list<array<string, string|null>>,
     *     accounts: list<array{id: string|null, name: string, client_id: string|null, category_id: string|null, login: string|null, url: string|null, notes: string|null, pass: string, key: string, tag_ids: list<string>}>
     * }
     */
    public function parse(string $xml): array
    {
        $document = SafeXml::load($xml);

        return [
            'meta' => $this->meta($document),
            'categories' => $this->records($document, 'Category', ['name', 'description']),
            'clients' => $this->records($document, 'Client', ['name', 'description']),
            'tags' => $this->records($document, 'Tag', ['name']),
            'accounts' => $this->accounts($document),
        ];
    }

    /**
     * @return array{generator: string|null, version: string|null, user: string|null}
     */
    private function meta(DOMDocument $document): array
    {
        $meta = $document->getElementsByTagName('Meta')->item(0);
        if (! $meta instanceof DOMElement) {
            return ['generator' => null, 'version' => null, 'user' => null];
        }

        return [
            'generator' => $this->text($meta, 'Generator'),
            'version' => $this->text($meta, 'Version'),
            'user' => $this->text($meta, 'User'),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return list<array<string, string|null>>
     */
    private function records(DOMDocument $document, string $tag, array $fields): array
    {
        $records = [];
        foreach ($document->getElementsByTagName($tag) as $element) {
            $record = ['id' => $this->attribute($element, 'id')];
            foreach ($fields as $field) {
                $record[$field] = $this->text($element, $field);
            }
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @return list<array{id: string|null, name: string, client_id: string|null, category_id: string|null, login: string|null, url: string|null, notes: string|null, pass: string, key: string, tag_ids: list<string>}>
     */
    private function accounts(DOMDocument $document): array
    {
        $accounts = [];
        foreach ($document->getElementsByTagName('Account') as $element) {
            $accounts[] = [
                'id' => $this->attribute($element, 'id'),
                'name' => $this->text($element, 'name') ?? '',
                'client_id' => $this->text($element, 'clientId'),
                'category_id' => $this->text($element, 'categoryId'),
                'login' => $this->text($element, 'login'),
                'url' => $this->text($element, 'url'),
                'notes' => $this->text($element, 'notes'),
                'pass' => $this->text($element, 'pass') ?? '',
                'key' => $this->text($element, 'key') ?? '',
                'tag_ids' => $this->tagIds($element),
            ];
        }

        return $accounts;
    }

    /**
     * @return list<string>
     */
    private function tagIds(DOMElement $account): array
    {
        $ids = [];
        foreach ($account->getElementsByTagName('tag') as $tag) {
            $id = trim($tag->getAttribute('id'));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function attribute(DOMElement $element, string $name): ?string
    {
        $value = trim($element->getAttribute($name));

        return $value === '' ? null : $value;
    }

    private function text(DOMElement $element, string $tag): ?string
    {
        $child = $element->getElementsByTagName($tag)->item(0);
        if (! $child instanceof DOMElement) {
            return null;
        }

        $value = trim($child->textContent);

        return $value === '' ? null : $value;
    }
}
