<?php

/**
 * @property PHPParser_Node_Expr $expr Expression
 */
class PHPParser_Node_Expr_ErrorSuppress extends PHPParser_Node_Expr
{
    /**
     * Constructs an error suppress node.
     *
     * @param PHPParser_Node_Expr $expr       Expression
     * @param int                 $line       Line
     * @param null|string         $docComment Nearest doc comment
     */
    public function __construct(PHPParser_Node_Expr $expr, $line = -1, $docComment = null) {
        parent::__construct(
            array(
                'expr' => $expr
            ),
            $line, $docComment
        );
    }
}