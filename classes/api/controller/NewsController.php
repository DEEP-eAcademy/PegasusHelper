<?php

namespace SRAG\PegasusHelper\api\controller;

use ILIAS\News\Data\NewsCriteria;
use ILIAS\News\InternalDataService as NewsInternalDataService;
use ILIAS\News\InternalDomainService as NewsInternalDomainService;
use ILIAS\News\InternalRepoService as NewsInternalRepoService;
use ilNewsItem;
use ilObject;
use ilObjectDefinition;
use ilObjectPlugin;
use SRAG\PegasusHelper\api\JsonResponse;
use SRAG\PegasusHelper\api\Request;

/**
 * Class NewsController
 *
 * Serves `GET /v2/ilias-app/news`. Ports the ILIAS REST plugin's `NewsAPI`
 * (`ilias_app_v2/models/NewsAPI.php`), which builds `InternalDomainService`
 * directly rather than through `$DIC->news()->internal()`, because that facade
 * also builds a GUI half that fails outside of a full ILIAS request.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class NewsController
{
    public function __invoke(Request $request, array $params): JsonResponse
    {
        global $DIC;

        /** @var ilObjectDefinition $definition */
        $definition = $DIC['objDefinition'];
        $language = $DIC->language();
        if (!in_array('news', $language->getUsedModules(), true)) {
            $language->loadLanguageModule('news');
        }

        $dataService = new NewsInternalDataService();
        $repoService = new NewsInternalRepoService($dataService, $DIC->database());
        $domainService = new NewsInternalDomainService($DIC, $repoService, $dataService);

        $user = $DIC->user();
        $period = ilNewsItem::_lookupUserPDPeriod((int) $user->getId());
        $criteria = new NewsCriteria(period: $period, only_public: false);

        $entries = [];
        foreach ($domainService->collection()->getNewsForUser($user, $criteria) as $rawEntry) {
            $objId = $rawEntry->getContextObjId();
            $objectType = $rawEntry->getContextObjType();

            $translatedType = $this->translateObjectType($definition, $language, $objectType);
            $objectTitle = (string) ilObject::_lookupTitle($objId);
            $subtitle = $this->translateSubtitle($definition, $objectType, $rawEntry->getTitle(), $rawEntry->isContentIsLangVar());

            $entries[] = [
                'newsId' => $rawEntry->getId(),
                'newsContext' => $rawEntry->getContextRefId(),
                'title' => sprintf('%s: %s', $translatedType, $objectTitle),
                'subtitle' => $subtitle,
                'content' => (string) ilNewsItem::determineNewsContent($objectType, $rawEntry->getContent(), $rawEntry->isContentTextIsLangVar()),
                'createDate' => $rawEntry->getCreationDate()->getTimestamp(),
                'updateDate' => $rawEntry->getUpdateDate()->getTimestamp(),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return $a['newsId'] - $b['newsId'];
        });

        return new JsonResponse($entries);
    }

    private function translateObjectType(ilObjectDefinition $definition, $language, string $objectType): string
    {
        if (!$definition->isPlugin($objectType)) {
            return (string) $language->txt("obj_$objectType");
        }

        return (string) ilObjectPlugin::lookupTxtById($objectType, "obj_$objectType");
    }

    private function translateSubtitle(ilObjectDefinition $definition, string $contextObjType, string $title, bool $contentIsLangVar): string
    {
        $subtitle = (string) ilNewsItem::determineNewsTitle($contextObjType, $title, $contentIsLangVar);

        $matches = [];
        if (preg_match('/^-(.*?)-$/', $subtitle, $matches) === 1 && $definition->isPlugin($contextObjType)) {
            // Fallback if the news service failed to translate a plugin title.
            return (string) ilObjectPlugin::lookupTxtById($contextObjType, $matches[1]);
        }

        return $subtitle;
    }
}
