<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Renderers;

class JsonLdRenderer
{
    public function script(array $schema): string
    {
        $json = json_encode(
            $schema,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
        );

        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">'.PHP_EOL.$json.PHP_EOL.'</script>';
    }
}
