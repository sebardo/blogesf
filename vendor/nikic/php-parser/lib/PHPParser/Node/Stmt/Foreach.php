<?php

/**
 * @property PHPParser_Node_Expr      $expr     Expression to iterate
 * @property null|PHPParser_Node_Expr $keyVar   Variable to assign key to
 * @property bool                     $byRef    Whether to assign value by reference
 * @property PHPParser_Node_Expr      $valueVar Variable to assign value to
 * @property PHPParser_Node[]         $stmts    Statements
 */
class PHPParser_Node_Stmt_Foreach extends PHPParser_Node_Stmt
{
    /**
     * Constructs a foreach node.
     *
     * @param PHPParser_Node_Expr $expr       Expression to iterate
     * @param PHPParser_Node_Expr $valueVar   Variable to assign value to
     * @param array               $subNodes   Array of the following optional subnodes:
     *                                        'keyVar' => null   : Variable to assign key to
     *                                        'byRef'  => false  : Whether to assign value by reference
     *                                        'stmts'  => array(): Statements
     * @param int                 $line       Line
     * @param null|string         $docComment Nearest doc comment
     */
    public function __construct(PHPParser_Node_Expr $expr, PHPParser_Node_Expr $valueVar, array $subNodes = array(), $line = -1, $docComment = null) {
        parent::__construct(
            $subNodes + array(
                'keyVar' => null,
                'byRef'  => false,
                'stmts'  => array(),
            ),
            $line, $docComment
        );
        $this->expr     = $expr;
        $this->valueVar = $valueVar;
    }
}