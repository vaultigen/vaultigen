<?php

use App\Services\Imports\Syspass\SyspassXmlParser;

it('parses a decrypted export into an intermediate representation', function () {
    $xml = '<Root>'
        .'<Meta><Generator>sysPass</Generator><Version>322.21031301</Version><User>admin</User></Meta>'
        .'<Categories>'
        .'<Category id="2"><name>Databases</name><description>db</description></Category>'
        .'<Category id="9"><name/><description/></Category>'
        .'</Categories>'
        .'<Clients><Client id="1"><name>Acme</name><description/></Client></Clients>'
        .'<Tags><Tag id="7"><name>ssh</name></Tag></Tags>'
        .'<Accounts>'
        .'<Account id="10"><name>Example</name><clientId>1</clientId><categoryId>2</categoryId>'
        .'<login>alice</login><url>https://example.com</url><notes>a note</notes>'
        .'<pass>secret</pass><key>key-material</key>'
        .'<tags><tag id="7"/><tag id="8"/><tag id=""/></tags></Account>'
        .'<Account id="11"><name></name></Account>'
        .'</Accounts>'
        .'</Root>';

    $ir = app(SyspassXmlParser::class)->parse($xml);

    expect($ir['meta'])->toBe(['generator' => 'sysPass', 'version' => '322.21031301', 'user' => 'admin'])
        ->and($ir['categories'])->toHaveCount(2)
        ->and($ir['categories'][0])->toBe(['id' => '2', 'name' => 'Databases', 'description' => 'db'])
        ->and($ir['categories'][1]['name'])->toBeNull()
        ->and($ir['clients'][0]['name'])->toBe('Acme')
        ->and($ir['tags'][0])->toBe(['id' => '7', 'name' => 'ssh'])
        ->and($ir['accounts'])->toHaveCount(2)
        ->and($ir['accounts'][0]['login'])->toBe('alice')
        ->and($ir['accounts'][0]['key'])->toBe('key-material')
        ->and($ir['accounts'][0]['tag_ids'])->toBe(['7', '8'])
        ->and($ir['accounts'][1]['name'])->toBe('')
        ->and($ir['accounts'][1]['pass'])->toBe('')
        ->and($ir['accounts'][1]['key'])->toBe('');
});

it('parses an export without metadata or identifiers', function () {
    $ir = app(SyspassXmlParser::class)->parse('<Root><Accounts><Account><name>No id</name></Account></Accounts></Root>');

    expect($ir['meta'])->toBe(['generator' => null, 'version' => null, 'user' => null])
        ->and($ir['categories'])->toBe([])
        ->and($ir['accounts'][0]['id'])->toBeNull()
        ->and($ir['accounts'][0]['login'])->toBeNull();
});
