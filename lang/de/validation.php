<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute muss akzeptiert werden.',
    'accepted_if' => ':attribute muss akzeptiert werden, wenn :other :value ist.',
    'active_url' => ':attribute muss eine gültige URL sein.',
    'after' => ':attribute muss ein Datum nach :date sein.',
    'after_or_equal' => ':attribute muss ein Datum nach dem :date oder gleich dem :date sein.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Zahlen, Binde- und Unterstriche enthalten.',
    'alpha_num' => ':attribute darf nur Buchstaben und Zahlen enthalten.',
    'any_of' => ':attribute ist ungültig.',
    'array' => ':attribute muss ein Array sein.',
    'array_keys' => ':attribute darf nur die folgenden Schlüssel enthalten: :values.',
    'ascii' => ':attribute darf nur einbyte-alphanumerische Zeichen und Symbole enthalten.',
    'base64' => ':attribute muss eine gültige Base64-Zeichenkette sein.',
    'before' => ':attribute muss ein Datum vor :date sein.',
    'before_or_equal' => ':attribute muss ein Datum vor dem :date oder gleich dem :date sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Elemente enthalten.',
        'file' => ':attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss entweder wahr oder falsch sein.',
    'can' => ':attribute enthält einen nicht erlaubten Wert.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'contains' => ':attribute fehlt ein erforderlicher Wert.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => ':attribute muss ein gültiges Datum sein.',
    'date_equals' => ':attribute muss ein Datum gleich :date sein.',
    'date_format' => ':attribute muss dem Format :format entsprechen.',
    'decimal' => ':attribute muss :decimal Dezimalstellen haben.',
    'declined' => ':attribute muss abgelehnt werden.',
    'declined_if' => ':attribute muss abgelehnt werden, wenn :other :value ist.',
    'different' => ':attribute und :other müssen unterschiedlich sein.',
    'digits' => ':attribute muss :digits Ziffern enthalten.',
    'digits_between' => ':attribute muss zwischen :min und :max Ziffern enthalten.',
    'dimensions' => ':attribute hat ungültige Bildabmessungen.',
    'distinct' => ':attribute enthält einen doppelten Wert.',
    'doesnt_contain' => ':attribute darf keinen der folgenden Werte enthalten: :values.',
    'doesnt_end_with' => ':attribute darf nicht mit einem der folgenden Werte enden: :values.',
    'doesnt_start_with' => ':attribute darf nicht mit einem der folgenden Werte beginnen: :values.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'encoding' => ':attribute muss in :encoding kodiert sein.',
    'ends_with' => ':attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'exists' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'extensions' => ':attribute muss eine der folgenden Dateiendungen haben: :values.',
    'file' => ':attribute muss eine Datei sein.',
    'filled' => ':attribute muss einen Wert haben.',
    'gt' => [
        'array' => ':attribute muss mehr als :value Elemente enthalten.',
        'file' => ':attribute muss größer als :value Kilobyte sein.',
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => ':attribute muss mindestens :value Elemente enthalten.',
        'file' => ':attribute muss größer oder gleich :value Kilobyte sein.',
        'numeric' => ':attribute muss größer oder gleich :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],
    'hex_color' => ':attribute muss eine gültige Hexadezimalfarbe sein.',
    'image' => ':attribute muss ein Bild sein.',
    'in' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'in_array' => ':attribute muss in :other vorhanden sein.',
    'in_array_keys' => ':attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'ip' => ':attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => ':attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => ':attribute muss eine gültige IPv6-Adresse sein.',
    'json' => ':attribute muss eine gültige JSON-Zeichenkette sein.',
    'list' => ':attribute muss eine Liste sein.',
    'lowercase' => ':attribute muss kleingeschrieben sein.',
    'lt' => [
        'array' => ':attribute muss weniger als :value Elemente enthalten.',
        'file' => ':attribute muss kleiner als :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => ':attribute darf nicht mehr als :value Elemente enthalten.',
        'file' => ':attribute muss kleiner oder gleich :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner oder gleich :value sein.',
        'string' => ':attribute muss höchstens :value Zeichen lang sein.',
    ],
    'mac_address' => ':attribute muss eine gültige MAC-Adresse sein.',
    'max' => [
        'array' => ':attribute darf nicht mehr als :max Elemente enthalten.',
        'file' => ':attribute darf nicht größer als :max Kilobyte sein.',
        'numeric' => ':attribute darf nicht größer als :max sein.',
        'string' => ':attribute darf nicht länger als :max Zeichen sein.',
    ],
    'max_digits' => ':attribute darf nicht mehr als :max Ziffern enthalten.',
    'mimes' => ':attribute muss eine Datei des Typs :values sein.',
    'mimetypes' => ':attribute muss eine Datei des Typs :values sein.',
    'min' => [
        'array' => ':attribute muss mindestens :min Elemente enthalten.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => ':attribute muss mindestens :min Ziffern enthalten.',
    'missing' => ':attribute darf nicht vorhanden sein.',
    'missing_if' => ':attribute darf nicht vorhanden sein, wenn :other :value ist.',
    'missing_unless' => ':attribute darf nicht vorhanden sein, es sei denn :other ist :value.',
    'missing_with' => ':attribute darf nicht vorhanden sein, wenn :values vorhanden ist.',
    'missing_with_all' => ':attribute darf nicht vorhanden sein, wenn :values vorhanden sind.',
    'multiple_of' => ':attribute muss ein Vielfaches von :value sein.',
    'not_in' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'not_regex' => 'Das Format von :attribute ist ungültig.',
    'numeric' => ':attribute muss eine Zahl sein.',
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Das angegebene :attribute ist in einem Datenleck aufgetaucht. Bitte wählen Sie ein anderes :attribute.',
    ],
    'present' => ':attribute muss vorhanden sein.',
    'present_if' => ':attribute muss vorhanden sein, wenn :other :value ist.',
    'present_unless' => ':attribute muss vorhanden sein, es sei denn :other ist :value.',
    'present_with' => ':attribute muss vorhanden sein, wenn :values vorhanden ist.',
    'present_with_all' => ':attribute muss vorhanden sein, wenn :values vorhanden sind.',
    'prohibited' => ':attribute ist nicht erlaubt.',
    'prohibited_if' => ':attribute ist nicht erlaubt, wenn :other :value ist.',
    'prohibited_if_accepted' => ':attribute ist nicht erlaubt, wenn :other akzeptiert wird.',
    'prohibited_if_declined' => ':attribute ist nicht erlaubt, wenn :other abgelehnt wird.',
    'prohibited_unless' => ':attribute ist nicht erlaubt, es sei denn :other ist in :values.',
    'prohibits' => ':attribute verbietet die Angabe von :other.',
    'regex' => 'Das Format von :attribute ist ungültig.',
    'required' => ':attribute ist erforderlich.',
    'required_array_keys' => ':attribute muss Einträge für Folgendes enthalten: :values.',
    'required_if' => ':attribute ist erforderlich, wenn :other :value ist.',
    'required_if_accepted' => ':attribute ist erforderlich, wenn :other akzeptiert wird.',
    'required_if_declined' => ':attribute ist erforderlich, wenn :other abgelehnt wird.',
    'required_unless' => ':attribute ist erforderlich, es sei denn :other ist in :values.',
    'required_with' => ':attribute ist erforderlich, wenn :values vorhanden ist.',
    'required_with_all' => ':attribute ist erforderlich, wenn :values vorhanden sind.',
    'required_without' => ':attribute ist erforderlich, wenn :values nicht vorhanden ist.',
    'required_without_all' => ':attribute ist erforderlich, wenn keiner der Werte :values vorhanden ist.',
    'same' => ':attribute und :other müssen übereinstimmen.',
    'size' => [
        'array' => ':attribute muss :size Elemente enthalten.',
        'file' => ':attribute muss :size Kilobyte groß sein.',
        'numeric' => ':attribute muss :size sein.',
        'string' => ':attribute muss :size Zeichen lang sein.',
    ],
    'starts_with' => ':attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => ':attribute muss eine Zeichenkette sein.',
    'timezone' => ':attribute muss eine gültige Zeitzone sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'uppercase' => ':attribute muss großgeschrieben sein.',
    'url' => ':attribute muss eine gültige URL sein.',
    'ulid' => ':attribute muss eine gültige ULID sein.',
    'uuid' => ':attribute muss eine gültige UUID sein.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
