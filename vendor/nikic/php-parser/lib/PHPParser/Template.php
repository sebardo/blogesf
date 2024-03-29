<?php

class PHPParser_Template
{
    protected $parser;
    protected $template;

    /**
     * Creates a new code template from a template string.
     *
     * @param PHPParser_Parser $parser   A parser instance
     * @param string           $template The template string
     */
    public function __construct(PHPParser_Parser $parser, $template) {
        $this->parser   = $parser;
        $this->template = $template;
    }

    /**
     * Get the statements of the template with the passed in placeholders
     * replaced.
     *
     * @param array $placeholders Placeholders
     *
     * @return PHPParser_Node[] Statements
     */
    public function getStmts(array $placeholders) {
        /*
         * TODO This is evil.
         * The lexer shouldn't be created in here, instead it should be a dependency, which
         * basically means that we'd need to have a LexerFactory (which seems strange).
         * An alternative solution would be to make the lexer work similar to how the parser
         * works. I.e. one would instantiate the Lexer only once and then pass the results
         * of ->lex() to the parser (which would then be the full tokens array). This design
         * seems cleaner, but comes at the expense of higher memory consumption, as the token
         * array can be quite large.
         */
        return $this->parser->parse(
            new PHPParser_Lexer_Emulative(
                $this->getTemplateWithPlaceholdersReplaced($placeholders)
            )
        );
    }

    protected function getTemplateWithPlaceholdersReplaced(array $placeholders) {
        if (empty($placeholders)) {
            return $this->template;
        }

        return strtr($this->template, $this->preparePlaceholders($placeholders));
    }

    /*
     * Prepare the placeholders for replacement. This means that
     * a) all placeholders will be surrounded with __.
     * b) ucfirst/lcfirst variations of the placeholders are generated.
     *
     * E.g. for an input array of ['foo' => 'bar'] the result will be
     * ['__foo__' => 'bar', '__Foo__' => 'Bar'].
     */
    protected function preparePlaceholders(array $placeholders) {
        $preparedPlaceholders = array();

        foreach ($placeholders as $name => $value) {
            $preparedPlaceholders['__' . $name . '__'] = $value;

            if (ctype_lower($name[0])) {
                $ucfirstName = ucfirst($name);
                if (!isset($placeholders[$ucfirstName])) {
                    $preparedPlaceholders['__' . $ucfirstName . '__'] = ucfirst($value);
                }
            }

            if (ctype_upper($name[0])) {
                $lcfirstName = lcfirst($name);
                if (!isset($placeholders[$lcfirstName])) {
                    $preparedPlaceholders['__' . $lcfirstName . '__'] = lcfirst($value);
                }
            }
        }

        return $preparedPlaceholders;
    }
}