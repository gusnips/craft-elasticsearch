<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\records;

use Craft;
use craft\base\Element;
use craft\helpers\ArrayHelper;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\events\SearchEvent;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\elasticsearch\ActiveRecord;
use yii\helpers\Json;
use yii\helpers\VarDumper;

/**
 * @property string $title
 * @property string $uri
 * @property object|array $content
 */
class ElasticsearchRecord extends ActiveRecord
{
    public static $siteId;

    private $_schema;
    private $_attributes = ['title', 'summary', 'text', 'slug', 'uri', 'postDate', 'category', 'categoryNames', 'tags', 'sectionSlug', 'sectionId', 'authorId', 'author', 'username', 'section', 'content', 'result'];
    private $_element;
    private $_queryParams;
    private $_highlightParams;
    private $_searchFields = ['title'];

    CONST EVENT_BEFORE_CREATE_INDEX = 'beforeCreateIndex';
    CONST EVENT_BEFORE_SAVE = 'beforeSave';
    CONST EVENT_BEFORE_SEARCH = 'beforeSearch';

    /**
     * @inheritdoc
     */
    public function attributes(): array
    {
        return $this->_attributes;
    }

    public function init()
    {
        parent::init();

        // add extra fields as additional attributes
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            $this->addAttributes(array_keys($extraFields));
        }
    }

    /**
     * Return an array of Elasticsearch records for the given query
     * @param string $query
     * @return ElasticsearchRecord[]
     * @throws InvalidConfigException
     * @throws \yii\elasticsearch\Exception
     */
    public function search(string $query)
    {
        // Add extra fields to search parameters
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        $extraHighlighParams = [];
        if (!empty($extraFields)) {
            $this->setSearchFields(ArrayHelper::merge($this->getSearchFields(), array_keys($extraFields)));
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldHighlighter = ArrayHelper::getValue($fieldParams, 'highlighter');
                if (!empty($fieldHighlighter)) {
                    $extraHighlighParams[$fieldName] = $fieldHighlighter;
                }
            }
        }
        $highlightParams = $this->getHighlightParams();
        $highlightParams['fields'] = ArrayHelper::merge($highlightParams['fields'], $extraHighlighParams);
        $this->setHighlightParams($highlightParams);

        $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent(['query' => $query]));
        $queryParams = $this->getQueryParams($query);
        $highlightParams = $this->getHighlightParams();
        return self::find()->query($queryParams)->highlight($highlightParams)->limit(self::find()->count())->all();
    }

    /**
     * Try to guess the best Elasticsearch analyze for the current site language
     * @return string
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function siteAnalyzer(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }

        $analyzer = 'standard'; // Default analyzer
        $availableAnalyzers = [
            'ar'    => 'arabic',
            'hy'    => 'armenian',
            'eu'    => 'basque',
            'bn'    => 'bengali',
            'pt-BR' => 'brazilian',
            'bg'    => 'bulgarian',
            'ca'    => 'catalan',
            'cs'    => 'czech',
            'da'    => 'danish',
            'nl'    => 'dutch',
            'pl'    => 'stempel', // analysis-stempel plugin needed
            'en'    => 'english',
            'fi'    => 'finnish',
            'fr'    => 'french',
            'gl'    => 'galician',
            'de'    => 'german',
            'el'    => 'greek',
            'hi'    => 'hindi',
            'hu'    => 'hungarian',
            'id'    => 'indonesian',
            'ga'    => 'irish',
            'it'    => 'italian',
            'ja'    => 'cjk',
            'ko'    => 'cjk',
            'lv'    => 'latvian',
            'lt'    => 'lithuanian',
            'nb'    => 'norwegian',
            'fa'    => 'persian',
            'pt'    => 'portuguese',
            'ro'    => 'romanian',
            'ru'    => 'russian',
            //sorani, Kurdish language is not part of the Craft locals...
            // 'sk' no analyzer available at this time
            'es'    => 'spanish',
            'sv'    => 'swedish',
            'tr'    => 'turkish',
            'th'    => 'thai',
            'zh'    => 'cjk' //Chinese
        ];

        $siteLanguage = Craft::$app->getSites()->getSiteById(static::$siteId)->language;
        if (array_key_exists($siteLanguage, $availableAnalyzers)) {
            $analyzer = $availableAnalyzers[$siteLanguage];
        } else {
            $localParts = explode('-', Craft::$app->language);
            $siteLanguage = $localParts[0];
            if (array_key_exists($siteLanguage, $availableAnalyzers)) {
                $analyzer = $availableAnalyzers[$siteLanguage];
            }
        }

        return $analyzer;
    }

    /**
     * @param bool $runValidation
     * @param null $attributeNames
     * @return bool
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function save($runValidation = true, $attributeNames = null): bool
    {
        if (!self::indexExists()) {
            $this->createESIndex();
        }

        // Get the value of each extra field
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldValue = ArrayHelper::getValue($fieldParams, 'value');
                if (!empty($fieldValue)) {
                    if (is_callable($fieldValue)) {
                        $this->$fieldName = $fieldValue($this->getElement(), $this);
                    } else {
                        $this->$fieldName = $fieldValue;
                    }
                }
            }
        }

        $this->trigger(self::EVENT_BEFORE_SAVE, new Event());
        if (!$this->getIsNewRecord()) {
            $this->delete(); // pipeline in not supported by Document Update API :(
        }
        return $this->insert($runValidation, $attributeNames);
    }

    public function createESIndex()
    {
        $mapping = static::mapping();
        // Add extra fields to the mapping definition
        $extraFields = ElasticsearchPlugin::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldMapping = ArrayHelper::getValue($fieldParams, 'mapping');
                if ($fieldMapping) {
                    if (is_callable($fieldMapping)) {
                        $fieldMapping = $fieldMapping($this);
                    }
                    $mapping[static::type()]['properties'][$fieldName] = $fieldMapping;
                }
            }
        }
        // Set the schema
        $this->setSchema([
            'mappings' => $mapping,
        ]);
        $this->trigger(self::EVENT_BEFORE_CREATE_INDEX, new Event());
        Craft::debug('Before create event - site: ' . self::$siteId . ' schema: ' . VarDumper::dumpAsString($this->getSchema()), __METHOD__);
        self::createIndex($this->getSchema());
    }

    /**
     * Return if the Elasticsearch index already exists or not
     * @return bool
     * @throws InvalidConfigException If the `$siteId` isn't set*
     */
    protected static function indexExists(): bool
    {
        $db = static::getDb();
        $command = $db->createCommand();
        return (bool)$command->indexExists(static::index());
    }

    /**
     * @return mixed|\yii\elasticsearch\Connection
     */
    public static function getDb()
    {
        return ElasticsearchPlugin::getConnection();
    }

    /**
     * @return string
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function index(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }
        return 'craft-entries_' . static::$siteId;
    }

    /**
     * Create this model's index in Elasticsearch
     * @param array $schema The Elascticsearch index definition schema
     * @param bool $force
     * @throws InvalidConfigException If the `$siteId` isn't set
     * @throws \yii\elasticsearch\Exception If an error occurs while communicating with the Elasticsearch server
     */
    public static function createIndex(array $schema, $force = false)
    {
        $db = static::getDb();
        $command = $db->createCommand();

        if ($force === true && $command->indexExists(static::index())) {
            self::deleteIndex();
        }
        $command->createIndex(static::index(), $schema);
    }

    /**
     * Delete this model's index
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function deleteIndex()
    {
        $db = static::getDb();
        $command = $db->createCommand();
        if ($command->indexExists(static::index())) {
            $command->deleteIndex(static::index());
        }
    }

    /**
     * @return array
     * @throws InvalidConfigException If the `$siteId` isn't set
     */
    public static function mapping(): array
    {
        $analyzer = self::siteAnalyzer();
        $mapping = [
            static::type() => [
                'properties' => [
                    'title'       => [
                        'type'     => 'text',
                        'store'    => true,
                    ],
                    'summary'       => [
                        'type'     => 'text',
                        'store'    => true,
                    ],
                    'text'       => [
                        'type'     => 'text',
                        'store'    => true,
                    ],
                    'slug'         => [
                        'type'  => 'keyword',
                    ],
                    'uri'         => [
                        'type'  => 'keyword',
                    ],
                    'postDate'         => [
                        'type'  => 'date',
                    ],
                    'sectionId'    => [
                        'type'  => 'integer',
                    ],
                    'section'=>[
                        'type'  => 'nested',
						'enabled' => false,
                    ],
                    'sectionSlug'=> [
                        'type'  => 'keyword',
                    ],
                    'authorId'    => [
                        'type'  => 'integer',
                    ],
                    'author'=>[
                        'type'  => 'nested',
						'enabled' => false,
                    ],
                    'username'=>[
                        'type'  => 'keyword',
                    ],
                    'category'    => [
                        'type'  => 'object',
                    ],
                    'categoryNames'    => [
                        'type'  => 'keyword',
                    ],
                    'tags'    => [
                        'type'  => 'object',
                    ],
                    'content'      => [
                        'type'  => 'nested',
                        'enabled' => false,
                    ],
                    'result'      => [
                        'type'  => 'nested',
                        'enabled' => false,
                    ],
                ],
            ],
        ];

        return $mapping;
    }

    /**
     * @return mixed
     */
    public function getSchema()
    {
        return $this->_schema;
    }

    /**
     * @param mixed $schema
     */
    public function setSchema($schema)
    {
        $this->_schema = $schema;
    }

    public function addAttributes(array $attributes)
    {
        $this->_attributes = ArrayHelper::merge($this->_attributes, $attributes);
    }

    /**
     * @return Element
     */
    public function getElement(): Element
    {
        return $this->_element;
    }

    /**
     * @param mixed $element
     */
    public function setElement(Element $element)
    {
        $this->_element = $element;
    }

    /**
     * @param $query the search value input
     * @return mixed
     * @throws InvalidConfigException
     */
    public function getQueryParams($query)
    {
        if ($this->_queryParams === null) {
            $this->_queryParams = [
                'multi_match' => [
                    'fields'   => $this->getSearchFields(),
                    'query'    => $query,
                    'analyzer' => self::siteAnalyzer(),
                    'operator' => 'and',
                ],
            ];
        }
        return $this->_queryParams;
    }

    /**
     * @param mixed $queryParams
     */
    public function setQueryParams($queryParams)
    {
        $this->_queryParams = $queryParams;
    }

    /**
     * @return mixed
     */
    public function getHighlightParams()
    {
        if (is_null($this->_highlightParams)) {
            $this->_highlightParams = ArrayHelper::merge(ElasticsearchPlugin::getInstance()->settings->highlight);
        }
        return $this->_highlightParams;
    }

    /**
     * @param mixed $highlightParams
     */
    public function setHighlightParams($highlightParams)
    {
        $this->_highlightParams = $highlightParams;
    }

    /**
     * @return array
     */
    public function getSearchFields(): array
    {
        return $this->_searchFields;
    }

    /**
     * @param array $searchFields
     */
    public function setSearchFields(array $searchFields)
    {
        $this->_searchFields = $searchFields;
    }
}
