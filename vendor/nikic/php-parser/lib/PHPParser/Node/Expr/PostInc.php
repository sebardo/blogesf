<?php

/**
 * @property PHPParser_Node_Expr $var Variable
 */
class PHPParser_Node_Expr_PostInc extends PHPParser_Node_Expr
{
    /**
     * Constructs a post increment node.
     *
     * @param PHPParser_Node_Expr $var        Variable
     * @param int                 $line       Line
     * @param null|string         $docComment Nearest doc comment
     */
    public function __construct(PHPParser_Node_Expr $var, $line = -1, $docComment = null) {
        parent::__construct(
            array(
                'var' => $var
            ),
            $line, $docComment
        );
    }
}