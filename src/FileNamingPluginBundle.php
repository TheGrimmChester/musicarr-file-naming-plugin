<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin;

use App\Plugin\AbstractPluginBundle;
use App\Plugin\PluginInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class FileNamingPluginBundle extends AbstractPluginBundle implements PluginInterface
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Load plugin services
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../config')
        );
        $loader->load('services.yaml');

        // Register Doctrine ORM mappings
        if ($container->hasExtension('doctrine')) {
            $container->loadFromExtension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'FileNamingPlugin' => [
                            'is_bundle' => false,
                            'dir' => __DIR__ . '/Entity',
                            'prefix' => 'Musicarr\FileNamingPlugin\Entity',
                            'alias' => 'FileNamingPlugin',
                            'type' => 'attribute',
                        ],
                    ],
                ],
            ]);
        }

        // Register Twig paths
        if ($container->hasExtension('twig')) {
            $container->loadFromExtension('twig', [
                'paths' => [
                    __DIR__ . '/../templates' => 'MusicarrFileNamingPlugin',
                ],
            ]);
        }

        // Register Doctrine Migrations
        if ($container->hasExtension('doctrine_migrations')) {
            $container->loadFromExtension('doctrine_migrations', [
                'migrations_paths' => [
                    'MusicarrFileNamingPluginDoctrineMigrations' => __DIR__ . '/../migrations/%env(string:MIGRATIONS_SUBPATH)%',
                ],
            ]);
        }

        // Register Sylius TwigHooks
        if ($container->hasExtension('sylius_twig_hooks')) {
            $container->loadFromExtension('sylius_twig_hooks', [
                'hooks' => [
                    'track.actions' => [
                        'file-naming-rename' => [
                            'template' => '@MusicarrFileNamingPlugin/album/track_rename_button.html.twig',
                            'priority' => 50,
                        ],
                    ],
                    'album.actions' => [
                        'file-naming-album-rename' => [
                            'template' => '@MusicarrFileNamingPlugin/album/album_rename_button.html.twig',
                            'priority' => 50,
                        ],
                    ],
                    'album.modals' => [
                        'file-naming-rename-modal' => [
                            'template' => '@MusicarrFileNamingPlugin/album/rename_modal.html.twig',
                            'priority' => 50,
                        ],
                    ],
                ],
            ]);
        }

        // Register translation paths
        if ($container->hasExtension('framework')) {
            $container->loadFromExtension('framework', [
                'translator' => [
                    'paths' => [
                        __DIR__ . '/../translations',
                    ],
                ],
            ]);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public static function getPluginName(): string
    {
        return 'file-naming-plugin';
    }

    public static function getVersion(): string
    {
        return '1.0.0';
    }

    public static function getAuthor(): string
    {
        return 'Musicarr Team';
    }

    public static function getDescription(): string
    {
        return 'File naming and renaming plugin for Musicarr';
    }

    public function getPluginPath(): string
    {
        return __DIR__ . '/..';
    }
}
