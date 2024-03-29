<?php

/**
 * @property PHPParser_Node_Name[]               $traits      Traits
 * @property PHPParser_Node_TraitUseAdaptation[] $adaptations Adaptations
 */
class PHPParser_Node_Stmt_TraitUse extends PHPParser_Node_Stmt
{
    /**
     * Constructs a trait use node.
     *
     * @param PHPParser_Node_Name[]               $traits      Traits
     * @param PHPParser_Node_TraitUseAdaptation[] $adaptations Adaptations
     * @param int                                 $line        Line
     * @param null|string                         $docComment  Nearest doc comment
     */
    public function __construct(array $traits, array $adaptations = array(), $line = -1, $docComment = null) {
        parent::__construct(
            array(
                'traits'      => $traits,
                'adaptations' => $adaptations,
            ),
            $line, $docComment
        );
    }
}