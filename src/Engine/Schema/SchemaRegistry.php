<?php

declare(strict_types=1);

namespace App\Engine\Schema;

final class SchemaRegistry
{
    /** @var array<int, Schema> */
    private array $map;

    public function __construct()
    {
        $this->map = [
            1  => new HttpRedirectSchema(301),
            2  => new HttpRedirectSchema(302),
            3  => new HttpRedirectSchema(303),
            4  => new HttpRedirectSchema(307),
            5  => new HttpRedirectSchema(308),
            6  => new MetaRefreshSchema(),
            7  => new DoubleMetaRefreshSchema(),
            8  => new IframeSchema(),
            9  => new HtmlPageSchema(),
            10 => new ShowTextSchema(),
            11 => new JsonSchema(),
            12 => new CurlProxySchema(),
            13 => new NoActionSchema(),
            14 => new HttpCodeSchema(),
            15 => new FormulaSchema(),
            16 => new JsRedirectSchema(),
        ];
    }

    public function get(int $schemaId): Schema
    {
        return $this->map[$schemaId] ?? throw new \InvalidArgumentException("unknown schema_id: {$schemaId}");
    }
}
