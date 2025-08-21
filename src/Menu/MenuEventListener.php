<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Menu;

use App\Menu\Event\MenuEvent;
use App\Menu\MenuNode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MenuEventListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MenuEvent::NAME => 'onBuildMenu',
        ];
    }

    public function onBuildMenu(MenuEvent $event): void
    {
        // Find existing content_management node and add file management items
        $contentManagement = $event->findNodeById('content_management');
        if ($contentManagement) {
            $contentManagement->addChild(
                (new MenuNode('renaming', 'nav.renaming'))
                    ->setRoute('file_renaming_index')
                    ->setIcon('fas fa-file-signature')
                    ->setPriority(70)
                    ->setTranslationKey('nav.renaming')
            );
        }

        // Find existing configuration node and add patterns item
        $configuration = $event->findNodeById('configuration');
        if ($configuration) {
            $configuration->addChild(
                (new MenuNode('patterns', 'nav.patterns'))
                    ->setRoute('file_naming_patterns_index')
                    ->setIcon('fas fa-file-code')
                    ->setPriority(60)
                    ->setTranslationKey('nav.patterns')
            );
        }
    }
}
