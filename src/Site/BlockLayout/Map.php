<?php
namespace Mapping\Site\BlockLayout;

use Composer\Semver\Comparator;
use NumericDataTypes\DataType\Timestamp;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\HtmlPurifier;
use Zend\View\Renderer\PhpRenderer;

class Map extends AbstractBlockLayout
{
    /**
     * @var HtmlPurifier
     */
    protected $htmlPurifier;

    /**
     * @var ModuleManager
     */
    protected $moduleManager;

    public function __construct(HtmlPurifier $htmlPurifier, ModuleManager $moduleManager)
    {
        $this->htmlPurifier = $htmlPurifier;
        $this->moduleManager = $moduleManager;
    }

    public function getLabel()
    {
        return 'Map'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $block->setData($this->filterBlockData($block->getData()));

        // Validate attachments.
        $itemIds = [];
        $attachments = $block->getAttachments();
        foreach ($attachments as $attachment) {
            // When an item was removed from the base, it should be removed.
            $item = $attachment->getItem();
            if (!$item) {
                $attachments->removeElement($attachment);
                continue;
            }
            // Duplicate items are redundant, so remove them.
            $itemId = $item->getId();
            if (in_array($itemId, $itemIds)) {
                $attachments->removeElement($attachment);
            }
            $itemIds[] = $itemId;
            // Media and caption are unneeded.
            $attachment->setMedia(null);
            $attachment->setCaption('');
        }
    }

    public function prepareForm(PhpRenderer $view)
    {
        $view->headScript()->appendFile($view->assetUrl('js/mapping-block-form.js', 'Mapping'));
        $view->headLink()->appendStylesheet($view->assetUrl('vendor/leaflet/leaflet.css', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('vendor/leaflet/leaflet.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('js/control.default-view.js', 'Mapping'));
    }

    public function form(PhpRenderer $view, SiteRepresentation $site,
        SitePageRepresentation $page = null, SitePageBlockRepresentation $block = null
    ) {
        $data = $block ? $this->filterBlockData($block->data()) : $this->filterBlockData([]);
        $form = $view->partial(
            'common/block-layout/mapping-block-form',
            ['data' => $data]
        );
        if ($this->timelineIsAvailable()) {
            $form .= $view->partial(
                'common/block-layout/mapping-block-timeline-form',
                ['data' => $data]
            );
        }
        $form .= $view->blockAttachmentsForm($block, true, ['has_markers' => true]);
        return $form;
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $this->filterBlockData($block->data());
        $isTimeline = (bool) $data['timeline']['data_type_properties'];
        $timelineIsAvailable = $this->timelineIsAvailable();

        // Get markers (and events, if applicable) from the attached items.
        $events = [];
        $markers = [];
        foreach ($block->attachments() as $attachment) {
            $item = $attachment->item();
            if (!$item) {
                // This attachment has no item. Do not add markers.
                continue;
            }
            if ($isTimeline && $timelineIsAvailable) {
                // Set the timeline event for this item.
                $event = $this->getTimelineEvent($item, $data['timeline']['data_type_properties'], $view);
                if ($event) {
                    $events[] = $event;
                }
            }
            // Set the map markers for this item.
            $itemMarkers = $view->api()->search('mapping_markers', ['item_id' => $item->id()])->getContent();
            $markers = array_merge($markers, $itemMarkers);
        }

        return $view->partial('common/block-layout/mapping-block', [
            'data' => $data,
            'markers' => $markers,
            'isTimeline' => $isTimeline,
            'timelineData' => $this->getTimelineData($events, $data, $view),
            'timelineOptions' => $this->getTimelineOptions($data),
        ]);
    }

    /**
     * Filter Map block data.
     *
     * We filter data on input and output to ensure a valid format, regardless
     * of version.
     *
     * @param array $data
     * @return array
     */
    protected function filterBlockData($data)
    {
        // Filter the defualt view data.
        $bounds = null;
        if (isset($data['bounds'])
            && 4 === count(array_filter(explode(',', $data['bounds']), 'is_numeric'))
        ) {
            $bounds = $data['bounds'];
        }

        // Filter the WMS overlay data.
        $wmsOverlays = [];
        if (isset($data['wms']) && is_array($data['wms'])) {
            foreach ($data['wms'] as $wmsOverlay) {
                // WMS data must have label and base URL.
                if (is_array($wmsOverlay)
                    && isset($wmsOverlay['label'])
                    && isset($wmsOverlay['base_url'])
                ) {
                    $layers = '';
                    if (isset($wmsOverlay['layers']) && '' !== trim($wmsOverlay['layers'])) {
                        $layers = $wmsOverlay['layers'];
                    }
                    $wmsOverlay['layers'] = $layers;

                    $styles = '';
                    if (isset($wmsOverlay['styles']) && '' !== trim($wmsOverlay['styles'])) {
                        $styles = $wmsOverlay['styles'];
                    }
                    $wmsOverlay['styles'] = $styles;

                    $open = null;
                    if (isset($wmsOverlay['open']) && $wmsOverlay['open']) {
                        $open = true;
                    }
                    $wmsOverlay['open'] = $open;

                    $wmsOverlays[] = $wmsOverlay;
                }
            }
        }

        // Filter the WMTS overlay data.
        $wmtsOverlays = [];
        if (isset($data['wmts']) && is_array($data['wmts'])) {
            foreach ($data['wmts'] as $wmtsOverlay) {
                // WMTS data must have label, URL, tile matrix set, and style.
                if (is_array($wmtsOverlay)
                    && isset($wmtsOverlay['label'])
                    && isset($wmtsOverlay['url'])
                    && isset($wmtsOverlay['tile_matrix_set'])
                    && isset($wmtsOverlay['style'])
                ) {
                    $open = null;
                    if (isset($wmtsOverlay['open']) && $wmtsOverlay['open']) {
                        $open = true;
                    }
                    $wmtsOverlay['open'] = $open;
                    $wmtsOverlays[] = $wmtsOverlay;
                }
            }
        }

        // Filter the timeline data.
        $timeline = [
            'title_headline' => null,
            'title_text' => null,
            'fly_to' => null,
            'data_type_properties' => null,
        ];
        if (isset($data['timeline']) && is_array($data['timeline'])) {
            if (isset($data['timeline']['title_headline'])) {
                $timeline['title_headline'] = $this->htmlPurifier->purify($data['timeline']['title_headline']);
            }
            if (isset($data['timeline']['title_text'])) {
                $timeline['title_text'] = $this->htmlPurifier->purify($data['timeline']['title_text']);
            }
            if (isset($data['timeline']['fly_to']) && is_numeric($data['timeline']['fly_to'])) {
                $timeline['fly_to'] = $data['timeline']['fly_to'];
            }
            if (isset($data['timeline']['data_type_properties'])) {
                // Anticipate future use of multiple numeric properties per
                // timeline by saving an array of properties.
                if (is_string($data['timeline']['data_type_properties'])) {
                    $data['timeline']['data_type_properties'] = [$data['timeline']['data_type_properties']];
                }
                if (is_array($data['timeline']['data_type_properties'])) {
                    foreach ($data['timeline']['data_type_properties'] as $dataTypeProperty) {
                        if (is_string($dataTypeProperty)) {
                            $dataTypeProperty = explode(':', $dataTypeProperty);
                            if (3 === count($dataTypeProperty)) {
                                list($namespace, $type, $propertyId) = $dataTypeProperty;
                                if ('numeric' === $namespace
                                    && in_array($type, ['timestamp', 'interval'])
                                    && is_numeric($propertyId)
                                ) {
                                    $timeline['data_type_properties'][] = sprintf('%s:%s:%s', $namespace, $type, $propertyId);
                                }
                            }
                        }
                    }
                }
            }
        }

        return [
            'bounds' => $bounds,
            'wms' => $wmsOverlays,
            'wmts' => $wmtsOverlays,
            'timeline' => $timeline,
        ];
    }

    /**
     * Is the timeline feature available?
     *
     * @return bool
     */
    public function timelineIsAvailable()
    {
        // Available when the NumericDataTypes module is active and the version
        // >= 1.1.0 (when it introduced interval data type).
        $module = $this->moduleManager->getModule('NumericDataTypes');
        return (
            $module
            && ModuleManager::STATE_ACTIVE === $module->getState()
            && Comparator::greaterThanOrEqualTo($module->getDb('version'), '1.1.0')
        );
    }

    /**
     * Get timeline options.
     *
     * @see https://timeline.knightlab.com/docs/options.html
     * @param srray $data
     * @return array
     */
    public function getTimelineOptions(array $data)
    {
        return [
            'debug' => false,
            'timenav_position' => 'bottom',
        ];
    }

    /**
     * Get timeline data.
     *
     * @see https://timeline.knightlab.com/docs/json-format.html
     * @param array $events
     * @param array $data
     * @param PhpRenderer $view
     * @return array
     */
    public function getTimelineData(array $events, array $data, PhpRenderer $view)
    {
        $timelineData = [
            'title' => null,
            'events' => $events,
        ];
        // Set the timeline title.
        if (isset($data['timeline']['title_headline']) || isset($data['timeline']['title_text'])) {
            $timelineData['title'] = [
                'text' => [
                    'headline' => $data['timeline']['title_headline'],
                    'text' => $data['timeline']['title_text'],
                ],
            ];
        }
        return $timelineData;
    }

    /**
     * Get a timeline event.
     *
     * @see https://timeline.knightlab.com/docs/json-format.html#json-slide
     * @param ItemRepresentation $item
     * @param array $dataTypeProperties
     * @return array
     */
    public function getTimelineEvent($item, array $dataTypeProperties, $view)
    {
        $property = null;
        $dataType = null;
        $value = null;
        foreach ($dataTypeProperties as $dataTypeProperty) {
            $dataTypeProperty = explode(':', $dataTypeProperty);
            try {
                $property = $view->api()->read('properties', $dataTypeProperty[2])->getContent();
            } catch (NotFoundException $e) {
                // Invalid property.
                continue;
            }
            $dataType = sprintf('%s:%s', $dataTypeProperty[0], $dataTypeProperty[1]);
            $value = $item->value($property->term(), ['type' => $dataType]);
            if ($value) {
                // Set only the first matching numeric value.
                break;
            }
        }
        if (!$value) {
            // This item has no numeric values.
            return;
        }

        // Set the unique ID and "text" object.
        $title = $item->value('dcterms:title');
        $description = $item->value('dcterms:description');
        $event = [
            'unique_id' => (string) $item->id(), // must cast to string
            'text' => [
                'headline' => $item->link($item->displayTitle(), null, ['target' => '_blank']),
                'text' => $item->displayDescription(),
            ],
        ];

        // Set the "media" object.
        $media = $item->primaryMedia();
        if ($media) {
            $event['media'] = [
                'url' => $media->thumbnailUrl('large'),
                'thumbnail' => $media->thumbnailUrl('medium'),
                'link' => $item->url(),
            ];
        }

        // Set the start and end "date" objects.
        if ('numeric:timestamp' === $dataType) {
            $dateTime = Timestamp::getDateTimeFromValue($value->value());
            $event['start_date'] = [
                'year' => $dateTime['year'],
                'month' => $dateTime['month'],
                'day' => $dateTime['day'],
                'hour' => $dateTime['hour'],
                'minute' => $dateTime['minute'],
                'second' => $dateTime['second'],
            ];
        } elseif ('numeric:interval' === $dataType) {
            list($intervalStart, $intervalEnd) = explode('/', $value->value());
            $dateTimeStart = Timestamp::getDateTimeFromValue($intervalStart);
            $event['start_date'] = [
                'year' => $dateTimeStart['year'],
                'month' => $dateTimeStart['month'],
                'day' => $dateTimeStart['day'],
                'hour' => $dateTimeStart['hour'],
                'minute' => $dateTimeStart['minute'],
                'second' => $dateTimeStart['second'],
            ];
            $dateTimeEnd = Timestamp::getDateTimeFromValue($intervalEnd, false);
            $event['end_date'] = [
                'year' => $dateTimeEnd['year'],
                'month' => $dateTimeEnd['month_normalized'],
                'day' => $dateTimeEnd['day_normalized'],
                'hour' => $dateTimeEnd['hour_normalized'],
                'minute' => $dateTimeEnd['minute_normalized'],
                'second' => $dateTimeEnd['second_normalized'],
            ];
            $event['display_date'] = sprintf(
                '%s — %s',
                $dateTimeStart['date']->format($dateTimeStart['format_render']),
                $dateTimeEnd['date']->format($dateTimeEnd['format_render'])
            );
        }
        return $event;
    }
}
