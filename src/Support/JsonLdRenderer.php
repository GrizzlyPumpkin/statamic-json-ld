<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Support;

class JsonLdRenderer
{
    public function script(array $schema, bool $pretty = false): string
    {
        $json = json_encode(
            $schema,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | ($pretty ? JSON_PRETTY_PRINT : 0)
        );

        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">'.PHP_EOL.$json.PHP_EOL.'</script>';
    }
}
