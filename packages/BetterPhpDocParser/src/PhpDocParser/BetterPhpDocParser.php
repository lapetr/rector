<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDocParser;

use Nette\Utils\Strings;
use PhpParser\Node as PhpNode;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use Rector\BetterPhpDocParser\Attributes\Ast\AttributeAwareNodeFactory;
use Rector\BetterPhpDocParser\Attributes\Ast\PhpDoc\AttributeAwarePhpDocNode;
use Rector\BetterPhpDocParser\Attributes\Attribute\Attribute;
use Rector\BetterPhpDocParser\Contract\ClassAwarePhpDocNodeFactoryInterface;
use Rector\BetterPhpDocParser\Contract\NameAwarePhpDocNodeFactoryInterface;
use Rector\BetterPhpDocParser\Contract\PhpDocNodeFactoryInterface;
use Rector\BetterPhpDocParser\Contract\PhpDocParserAwareInterface;
use Rector\BetterPhpDocParser\Printer\MultilineSpaceFormatPreserver;
use Rector\BetterPhpDocParser\ValueObject\StartEndValueObject;
use Rector\Configuration\CurrentNodeProvider;
use Symplify\PackageBuilder\Reflection\PrivatesAccessor;
use Symplify\PackageBuilder\Reflection\PrivatesCaller;

/**
 * @see \Rector\BetterPhpDocParser\Tests\PhpDocParser\OrmTagParser\Class_\DoctrinePhpDocParserTest
 * @see \Rector\BetterPhpDocParser\Tests\PhpDocParser\OrmTagParser\Property_\OrmTagParserPropertyTest
 */
final class BetterPhpDocParser extends PhpDocParser
{
    /**
     * @var bool
     */
    private $isComment = false;

    /**
     * @var PrivatesCaller
     */
    private $privatesCaller;

    /**
     * @var PrivatesAccessor
     */
    private $privatesAccessor;

    /**
     * @var AttributeAwareNodeFactory
     */
    private $attributeAwareNodeFactory;

    /**
     * @var MultilineSpaceFormatPreserver
     */
    private $multilineSpaceFormatPreserver;

    /**
     * @var PhpDocNodeFactoryInterface[]
     */
    private $phpDocNodeFactories = [];

    /**
     * @var CurrentNodeProvider
     */
    private $currentNodeProvider;

    /**
     * @var ClassAnnotationMatcher
     */
    private $classAnnotationMatcher;

    /**
     * @param PhpDocNodeFactoryInterface[] $phpDocNodeFactories
     */
    public function __construct(
        TypeParser $typeParser,
        ConstExprParser $constExprParser,
        AttributeAwareNodeFactory $attributeAwareNodeFactory,
        MultilineSpaceFormatPreserver $multilineSpaceFormatPreserver,
        CurrentNodeProvider $currentNodeProvider,
        ClassAnnotationMatcher $classAnnotationMatcher,
        array $phpDocNodeFactories = []
    ) {
        parent::__construct($typeParser, $constExprParser);

        $this->privatesCaller = new PrivatesCaller();
        $this->privatesAccessor = new PrivatesAccessor();
        $this->attributeAwareNodeFactory = $attributeAwareNodeFactory;
        $this->multilineSpaceFormatPreserver = $multilineSpaceFormatPreserver;
        $this->phpDocNodeFactories = $phpDocNodeFactories;
        $this->currentNodeProvider = $currentNodeProvider;
        $this->classAnnotationMatcher = $classAnnotationMatcher;
    }

    /**
     * @return AttributeAwarePhpDocNode|PhpDocNode
     */
    public function parse(TokenIterator $tokenIterator): PhpDocNode
    {
        $this->isComment = false;

        try {
            $tokenIterator->consumeTokenType(Lexer::TOKEN_OPEN_PHPDOC);
        } catch (ParserException $parserException) {
            // probably "//" start
            $this->isComment = true;
            $tokenIterator->consumeTokenType(Lexer::TOKEN_OTHER);
        }

        $tokenIterator->tryConsumeTokenType(Lexer::TOKEN_PHPDOC_EOL);

        $children = [];
        if (! $tokenIterator->isCurrentTokenType(Lexer::TOKEN_CLOSE_PHPDOC)) {
            $children[] = $this->parseChildAndStoreItsPositions($tokenIterator);
            while ($tokenIterator->tryConsumeTokenType(Lexer::TOKEN_PHPDOC_EOL) && ! $tokenIterator->isCurrentTokenType(
                Lexer::TOKEN_CLOSE_PHPDOC
            )) {
                $children[] = $this->parseChildAndStoreItsPositions($tokenIterator);
            }
        }

        if (! $this->isComment) {
            // might be in the middle of annotations
            $tokenIterator->tryConsumeTokenType(Lexer::TOKEN_CLOSE_PHPDOC);
        }

        $phpDocNode = new PhpDocNode(array_values($children));

        return $this->attributeAwareNodeFactory->createFromNode($phpDocNode);
    }

    public function parseTag(TokenIterator $tokenIterator): PhpDocTagNode
    {
        $tag = $this->resolveTag($tokenIterator);

        $phpDocTagValueNode = $this->parseTagValue($tokenIterator, $tag);

        return new PhpDocTagNode($tag, $phpDocTagValueNode);
    }

    public function parseTagValue(TokenIterator $tokenIterator, string $tag): PhpDocTagValueNode
    {
        // needed for reference support in params, see https://github.com/rectorphp/rector/issues/1734
        $tagValueNode = null;
        foreach ($this->phpDocNodeFactories as $phpDocNodeFactory) {
            // to prevent circular reference of this service
            if ($phpDocNodeFactory instanceof PhpDocParserAwareInterface) {
                $phpDocNodeFactory->setPhpDocParser($this);
            }

            // compare regardless sensitivity
            $currentPhpNode = $this->currentNodeProvider->getNode();
            if ($this->isTagMatchingPhpDocNodeFactory($tag, $phpDocNodeFactory, $currentPhpNode)) {
                $tagValueNode = $phpDocNodeFactory->createFromNodeAndTokens($currentPhpNode, $tokenIterator);
                if ($tagValueNode !== null) {
                    break;
                }
            }
        }

        // fallback to original parser
        if ($tagValueNode === null) {
            $tagValueNode = parent::parseTagValue($tokenIterator, $tag);
        }

        return $this->attributeAwareNodeFactory->createFromNode($tagValueNode);
    }

    private function parseChildAndStoreItsPositions(TokenIterator $tokenIterator): Node
    {
        $tokenStart = $this->getTokenIteratorIndex($tokenIterator);
        $phpDocNode = $this->privatesCaller->callPrivateMethod($this, 'parseChild', $tokenIterator);
        $tokenEnd = $this->getTokenIteratorIndex($tokenIterator);
        $startEndValueObject = new StartEndValueObject($tokenStart, $tokenEnd);

        $attributeAwareNode = $this->attributeAwareNodeFactory->createFromNode($phpDocNode);
        $attributeAwareNode->setAttribute(Attribute::PHP_DOC_NODE_INFO, $startEndValueObject);

        $possibleMultilineText = $this->multilineSpaceFormatPreserver->resolveCurrentPhpDocNodeText(
            $attributeAwareNode
        );

        if ($possibleMultilineText) {
            // add original text, for keeping trimmed spaces
            $originalContent = $this->getOriginalContentFromTokenIterator($tokenIterator);

            // we try to match original content without trimmed spaces
            $currentTextPattern = '#' . preg_quote($possibleMultilineText, '#') . '#s';
            $currentTextPattern = Strings::replace($currentTextPattern, '#(\s)+#', '\s+');
            $match = Strings::match($originalContent, $currentTextPattern);

            if (isset($match[0])) {
                $attributeAwareNode->setAttribute(Attribute::ORIGINAL_CONTENT, $match[0]);
            }
        }

        return $attributeAwareNode;
    }

    private function getOriginalContentFromTokenIterator(TokenIterator $tokenIterator): string
    {
        // @todo iterate through tokens...
        $originalTokens = $this->privatesAccessor->getPrivateProperty($tokenIterator, 'tokens');
        $originalContent = '';

        foreach ($originalTokens as $originalToken) {
            // skip opening
            if ($originalToken[1] === Lexer::TOKEN_OPEN_PHPDOC) {
                continue;
            }

            // skip closing
            if ($originalToken[1] === Lexer::TOKEN_CLOSE_PHPDOC) {
                continue;
            }

            if ($originalToken[1] === Lexer::TOKEN_PHPDOC_EOL) {
                $originalToken[0] = PHP_EOL;
            }

            $originalContent .= $originalToken[0];
        }

        return trim($originalContent);
    }

    private function getTokenIteratorIndex(TokenIterator $tokenIterator): int
    {
        return (int) $this->privatesAccessor->getPrivateProperty($tokenIterator, 'index');
    }

    private function resolveTag(TokenIterator $tokenIterator): string
    {
        $tag = $tokenIterator->currentTokenValue();

        $tokenIterator->next();

        // basic annotation
        if (Strings::match($tag, '#@(var|param|return|throws|property|deprecated)#')) {
            return $tag;
        }

        // is not e.g "@var "
        // join tags like "@ORM\Column" etc.
        if ($tokenIterator->currentTokenType() !== Lexer::TOKEN_IDENTIFIER) {
            return $tag;
        }
        $oldTag = $tag;

        $tag .= $tokenIterator->currentTokenValue();

        $isTagMatchedByFactories = $this->isTagMatchedByFactories($tag);
        if (! $isTagMatchedByFactories) {
            return $oldTag;
        }

        $tokenIterator->next();

        return $tag;
    }

    private function isTagMatchedByFactories(string $tag): bool
    {
        $currentPhpNode = $this->currentNodeProvider->getNode();
        foreach ($this->phpDocNodeFactories as $phpDocNodeFactory) {
            if ($this->isTagMatchingPhpDocNodeFactory($tag, $phpDocNodeFactory, $currentPhpNode)) {
                return true;
            }
        }

        return false;
    }

    private function isTagMatchingPhpDocNodeFactory(
        string $tag,
        PhpDocNodeFactoryInterface $phpDocNodeFactory,
        PhpNode $phpNode
    ): bool {
        // normalize
        $tag = ltrim($tag, '@');

        if ($phpDocNodeFactory instanceof NameAwarePhpDocNodeFactoryInterface) {
            if (Strings::lower($phpDocNodeFactory->getName()) === Strings::lower($tag)) {
                return true;
            }

            return false;
        }

        if ($phpDocNodeFactory instanceof ClassAwarePhpDocNodeFactoryInterface) {
            return $this->classAnnotationMatcher->isTagMatchToNodeAndClass(
                $tag,
                $phpNode,
                $phpDocNodeFactory->getClass()
            );
        }

        return false;
    }
}
