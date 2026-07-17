<?php

declare(strict_types=1);

namespace App\Shared\I18n;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class TranslatorFactory
{
    /** @var list<string> */
    public const SUPPORTED = ['ru', 'en'];
    public const DEFAULT   = 'ru';

    public function __construct(private readonly string $translationsDir) {}

    public function create(): Translator
    {
        $translator = new Translator(self::DEFAULT);
        $translator->setFallbackLocales([self::DEFAULT]);
        $translator->addLoader('yaml', new YamlFileLoader());

        foreach (self::SUPPORTED as $locale) {
            $path = $this->translationsDir . "/messages.{$locale}.yaml";
            if (is_file($path)) {
                $translator->addResource('yaml', $path, $locale);
            }
        }

        return $translator;
    }
}
