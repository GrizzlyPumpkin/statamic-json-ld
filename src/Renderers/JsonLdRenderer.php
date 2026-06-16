<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Renderers;

class JsonLdRenderer
{
    public function script(array $graph, array $data): string
    {
        $json = json_encode(
            [
                '@context' => 'https://schema.org',
                '@graph' => array_map(fn ($item) => $item->renderToArray($data), $graph),
            ],
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">'.PHP_EOL.$json.PHP_EOL.'</script>';
    }
}
