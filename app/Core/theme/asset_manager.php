<?php
declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Application;

class AssetManager
{
    private Application $app;
    private ThemeManager $themeManager;
    private array $assets = [];

    public function __construct(Application $app, ThemeManager $themeManager)
    {
        $this->app = $app;
        $this->themeManager = $themeManager;
    }

    public function addCSS(string $path, array $attributes = []): void
    {
        $this->assets['css'][] = [
            'path' => $path,
            'attributes' => array_merge(['rel' => 'stylesheet'], $attributes)
        ];
    }

    public function addJS(string $path, array $attributes = []): void
    {
        $this->assets['js'][] = [
            'path' => $path,
            'attributes' => $attributes
        ];
    }

    public function renderCSS(): string
    {
        $output = '';
        foreach ($this->assets['css'] ?? [] as $css) {
            $url = $this->resolveAssetUrl($css['path']);
            $attrs = $this->buildAttributes($css['attributes']);
            $output .= "<link href=\"{$url}\"{$attrs}>\n";
        }
        return $output;
    }

    public function renderJS(): string
    {
        $output = '';
        foreach ($this->assets['js'] ?? [] as $js) {
            $url = $this->resolveAssetUrl($js['path']);
            $attrs = $this->buildAttributes($js['attributes']);
            $output .= "<script src=\"{$url}\"{$attrs}></script>\n";
        }
        return $output;
    }

    public function getAssetUrl(string $path): string
    {
        return $this->resolveAssetUrl($path);
    }

    private function resolveAssetUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        if (strpos($path, '/') === 0) {
            return $path;
        }

        return $this->themeManager->getThemeUrl($path);
    }

    private function buildAttributes(array $attributes): string
    {
        $output = '';
        foreach ($attributes as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $output .= " {$key}";
                }
            } else {
                $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $output .= " {$key}=\"{$escaped}\"";
            }
        }
        return $output;
    }
}
