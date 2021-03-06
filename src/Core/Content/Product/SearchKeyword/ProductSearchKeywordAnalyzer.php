<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SearchKeyword;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\Filter\AbstractTokenFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\TokenizerInterface;
use Shopware\Core\Framework\Feature;

class ProductSearchKeywordAnalyzer implements ProductSearchKeywordAnalyzerInterface
{
    /**
     * @var TokenizerInterface
     */
    private $tokenizer;

    /**
     * @var AbstractTokenFilter|null
     */
    private $tokenFilter;

    public function __construct(TokenizerInterface $tokenizer, ?AbstractTokenFilter $tokenFilter = null)
    {
        $this->tokenizer = $tokenizer;
        $this->tokenFilter = $tokenFilter;
    }

    public function analyze(ProductEntity $product, Context $context): AnalyzedKeywordCollection
    {
        $keywords = new AnalyzedKeywordCollection();

        $keywords->add(new AnalyzedKeyword($product->getProductNumber(), 1000));

        $name = $product->getTranslation('name');
        if ($name) {
            $tokens = $this->tokenizer->tokenize((string) $name);

            if (Feature::isActive('FEATURE_NEXT_10552') && $this->tokenFilter) {
                $tokens = $this->tokenFilter->filter($tokens, $context);
            }

            foreach ($tokens as $token) {
                $keywords->add(new AnalyzedKeyword((string) $token, 700));
            }
        }

        if ($product->getManufacturer() && $product->getManufacturer()->getTranslation('name') !== null) {
            $keywords->add(new AnalyzedKeyword((string) $product->getManufacturer()->getTranslation('name'), 500));
        }
        if ($product->getManufacturerNumber()) {
            $keywords->add(new AnalyzedKeyword($product->getManufacturerNumber(), 500));
        }
        if ($product->getEan()) {
            $keywords->add(new AnalyzedKeyword($product->getEan(), 500));
        }
        if (!empty($product->getCustomSearchKeywords())) {
            foreach ($product->getCustomSearchKeywords() as $keyword) {
                $keywords->add(new AnalyzedKeyword($keyword, 800));
            }
        }

        return $keywords;
    }
}
