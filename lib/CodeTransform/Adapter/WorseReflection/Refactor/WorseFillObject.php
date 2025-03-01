<?php

namespace Phpactor\CodeTransform\Adapter\WorseReflection\Refactor;

use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Parser;
use Phpactor\CodeBuilder\Domain\Builder\SourceCodeBuilder;
use Phpactor\CodeBuilder\Domain\Code;
use Phpactor\CodeBuilder\Domain\Updater;
use Phpactor\CodeTransform\Adapter\WorseReflection\Helper\EmptyValueRenderer;
use Phpactor\CodeTransform\Domain\Refactor\FillObject;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextEdit;
use Phpactor\TextDocument\TextEdits;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Core\Reflection\ReflectionObjectCreationExpression;
use Phpactor\WorseReflection\Core\Reflection\ReflectionParameter;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\WorseReflection\Core\Type\HasEmptyType;
use Phpactor\WorseReflection\Core\Type\ReflectedClassType;
use Phpactor\WorseReflection\Reflector;

class WorseFillObject implements FillObject
{
    private Reflector $reflector;

    private Parser $parser;

    private EmptyValueRenderer $valueRenderer;

    private Updater $updater;

    private bool $namedParameters;

    private bool $hint;

    public function __construct(
        Reflector $reflector,
        Parser $parser,
        Updater $updater,
        bool $namedParameters = true,
        bool $hint = true
    ) {
        $this->reflector = $reflector;
        $this->parser = $parser;
        $this->valueRenderer = new EmptyValueRenderer();
        $this->updater = $updater;
        $this->namedParameters = $namedParameters;
        $this->hint = $hint;
    }

    public function fillObject(TextDocument $document, ByteOffset $offset): TextEdits
    {
        $node = $this->parser->parseSourceFile($document->__toString())->getDescendantNodeAtPosition($offset->toInt());
        $node = $node->getFirstAncestor(ObjectCreationExpression::class);
        if (!$node instanceof ObjectCreationExpression) {
            return TextEdits::none();
        }
        try {
            $offset = $this->reflector->reflectNode($document, $node->getStartPosition());
        } catch (NotFound $notFound) {
            return TextEdits::none();
        }

        if (!$offset instanceof ReflectionObjectCreationExpression) {
            return TextEdits::none();
        }

        // do not support existing arguments
        if ($offset->arguments()->count() !== 0) {
            return TextEdits::none();
        }

        try {
            $constructor = $offset->class()->methods()->get('__construct');
        } catch (NotFound $notFound) {
            return TextEdits::none();
        }

        $args = [];

        $imports = [];
        foreach ($constructor->parameters() as $parameter) {
            assert($parameter instanceof ReflectionParameter);
            $parameterType = $parameter->type();
            if ($parameterType instanceof ReflectedClassType && $parameterType->isInterface()->isFalse()) {
                $imports[] = $parameterType;
            }
            $arg = [];
            if ($this->namedParameters) {
                $arg[] = sprintf(
                    '%s: ',
                    $parameter->name(),
                );
            }
            $arg[] = $this->renderEmptyValue($parameterType);

            if ($this->hint) {
                $arg[] = sprintf(' /** $%s %s */', $parameter->name(), $parameter->type()->__toString());
            }
            $args[] = implode('', $arg);
        }

        $sourceCode = SourceCodeBuilder::create();
        foreach ($imports as $import) {
            $sourceCode->use($import->__toString());
        }
        $textEdits = $this->updater->textEditsFor($sourceCode->build(), Code::fromString($document->__toString()));
        $textEdits = $textEdits->add(TextEdit::create($node->openParen->getEndPosition(), 0, implode(', ', $args)));

        return $textEdits;
    }

    private function renderEmptyValue(Type $type): string
    {
        if (!$type instanceof HasEmptyType) {
            return sprintf('/** %s */', $type->__toString());
        }

        return $this->valueRenderer->render($type->emptyType());
    }
}
