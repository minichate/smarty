<?php

/*
 * Project:     Smarty: the PHP compiling template engine
 * File:        Smarty_Compiler.class.php
 * Author:      Monte Ohrt <monte@ispi.net>
 *              Andrei Zmievski <andrei@php.net>
 *
 * Version:             1.4.4
 * Copyright:           2001 ispi of Lincoln, Inc.
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * You may contact the authors of Smarty by e-mail at:
 * monte@ispi.net
 * andrei@php.net
 *
 * Or, write to:
 * Monte Ohrt
 * CTO, ispi
 * 237 S. 70th suite 220
 * Lincoln, NE 68510
 *
 * The latest version of Smarty can be obtained from:
 * http://www.phpinsider.com/
 *
 */

class Smarty_Compiler extends Smarty {

    // internal vars
    var $_sectionelse_stack     =   array();    // keeps track of whether section had 'else' part
    var $_literal_blocks        =   array();    // keeps literal template blocks
    var $_php_blocks            =   array();    // keeps php code blocks
    var $_current_file          =   null;       // the current template being compiled
    var $_current_line_no       =   1;          // line number for error messages
        

/*======================================================================*\
    Function:   _compile_file()
    Input:      compile a template file
\*======================================================================*/
    function _compile_file($tpl_file, $template_source, &$template_compiled)
    {
        if ($this->security) {
            // do not allow php syntax to be executed unless specified
            if ($this->php_handling == SMARTY_PHP_ALLOW &&
                !$this->security_settings['PHP_HANDLING']) {
                $this->php_handling = SMARTY_PHP_PASSTHRU;
            }
        }
        
        // run template source through functions registered in prefilter_funcs
        if (is_array($this->prefilter_funcs) && count($this->prefilter_funcs) > 0) {
            foreach ($this->prefilter_funcs as $prefilter) {
                if (function_exists($prefilter)) {
                    $template_source = $prefilter($template_source);
                } else {
                    $this->_trigger_error_msg("prefilter function $prefilter does not exist.");
                }
            }
        }
                
        $this->_current_file = $tpl_file;
        $this->_current_line_no = 1;
        $ldq = preg_quote($this->left_delimiter, '!');
        $rdq = preg_quote($this->right_delimiter, '!');

        /* Pull out the literal blocks. */
        preg_match_all("!{$ldq}literal{$rdq}(.*?){$ldq}/literal{$rdq}!s", $template_source, $match);
        $this->_literal_blocks = $match[1];
        $template_source = preg_replace("!{$ldq}literal{$rdq}(.*?){$ldq}/literal{$rdq}!s",
                                        $this->quote_replace($this->left_delimiter.'literal'.$this->right_delimiter), $template_source);

        /* Pull out the php code blocks. */
        preg_match_all("!{$ldq}php{$rdq}(.*?){$ldq}/php{$rdq}!s", $template_source, $match);
        $this->_php_blocks = $match[1];
        $template_source = preg_replace("!{$ldq}php{$rdq}(.*?){$ldq}/php{$rdq}!s",
                                        $this->quote_replace($this->left_delimiter.'php'.$this->right_delimiter), $template_source);        
        
        /* Gather all template tags. */
        preg_match_all("!{$ldq}\s*(.*?)\s*{$rdq}!s", $template_source, $match);
        $template_tags = $match[1];
        /* Split content by template tags to obtain non-template content. */
        $text_blocks = preg_split("!{$ldq}.*?{$rdq}!s", $template_source);

        /* loop through text blocks */
        for ($curr_tb = 0; $curr_tb < count($text_blocks); $curr_tb++) {
            /* match anything within <? ?> */
            if (preg_match_all('!(<\?[^?]*?\?>|<script\s+language\s*=\s*[\"\']?php[\"\']?\s*>)!is', $text_blocks[$curr_tb], $sp_match)) {
                /* found at least one match, loop through each one */
                for ($curr_sp = 0; $curr_sp < count($sp_match[0]); $curr_sp++) {
                    if (preg_match('!^(<\?(php\s|\s|=\s)|<script\s*language\s*=\s*[\"\']?php[\"\']?\s*>)!is', $sp_match[0][$curr_sp])) {
                        /* php tag */
                        if ($this->php_handling == SMARTY_PHP_PASSTHRU) {
                            /* echo php contents */
                            $text_blocks[$curr_tb] = str_replace($sp_match[0][$curr_sp], '<?php echo \''.str_replace("'", "\'", $sp_match[0][$curr_sp]).'\'; ?>'."\n", $text_blocks[$curr_tb]);
                       } else if ($this->php_handling == SMARTY_PHP_QUOTE) {
                            /* quote php tags */
                            $text_blocks[$curr_tb] = str_replace($sp_match[0][$curr_sp], htmlspecialchars($sp_match[0][$curr_sp]), $text_blocks[$curr_tb]);
                        } else if ($this->php_handling == SMARTY_PHP_REMOVE) {
                            /* remove php tags */
                            if (substr($sp_match[0][$curr_sp], 0, 2) == '<?')
                                $text_blocks[$curr_tb] = str_replace($sp_match[0][$curr_sp], '', $text_blocks[$curr_tb]);
                            else
                                /* attempt to remove everything between <script ...> and </script> */
                                $text_blocks[$curr_tb] = preg_replace('!'.preg_quote($sp_match[0][$curr_sp], '!').'.*?</script\s*>!is', '', $text_blocks[$curr_tb]);
                        }
                    } else
                        /* echo the non-php tags */
                        $text_blocks[$curr_tb] = str_replace($sp_match[0][$curr_sp], '<?php echo \''.str_replace("'", "\'", $sp_match[0][$curr_sp]).'\'; ?>'."\n", $text_blocks[$curr_tb]);
                }
            }
        }

        /* Compile the template tags into PHP code. */
        $compiled_tags = array();
        for ($i = 0; $i < count($template_tags); $i++) {
            $this->_current_line_no += substr_count($text_blocks[$i], "\n");
            $compiled_tags[] = $this->_compile_tag($template_tags[$i]);
            $this->_current_line_no += substr_count($template_tags[$i], "\n");
        }

        $template_compiled = '';

        /* Interleave the compiled contents and text blocks to get the final result. */
        for ($i = 0; $i < count($compiled_tags); $i++) {
            $template_compiled .= $text_blocks[$i].$compiled_tags[$i];
        }
        $template_compiled .= $text_blocks[$i];

        /* Reformat data between 'strip' and '/strip' tags, removing spaces, tabs and newlines. */
        if (preg_match_all("!{$ldq}strip{$rdq}.*?{$ldq}/strip{$rdq}!s", $template_compiled, $match)) {
            $strip_tags = $match[0];
            $strip_tags_modified = preg_replace("!{$ldq}/?strip{$rdq}|[\t ]+$|^[\t ]+!m", '', $strip_tags);
            $strip_tags_modified = preg_replace('![\r\n]+!m', '', $strip_tags_modified);
            for ($i = 0; $i < count($strip_tags); $i++)
                $template_compiled = preg_replace("!{$ldq}strip{$rdq}.*?{$ldq}/strip{$rdq}!s",
                                                  $this->quote_replace($strip_tags_modified[$i]),
                                                  $template_compiled, 1);
        }

        // put header at the top of the compiled template
        $template_header = "<?php /* Smarty version ".$this->_version.", created on ".strftime("%Y-%m-%d %H:%M:%S")."\n";
        $template_header .= "         compiled from ".$tpl_file." */ ?>\n";
        $template_compiled = $template_header.$template_compiled;
        
        return true;
    }


/*======================================================================*\
    Function: _compile_tag
    Purpose:  Compile a template tag
\*======================================================================*/
    function _compile_tag($template_tag)
    {
        /* Matched comment. */
        if ($template_tag{0} == '*' && $template_tag{strlen($template_tag)-1} == '*')
            return '';

        $qstr_regexp = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';

        /* Split tag into two parts: command and the arguments. */
        preg_match('/^(
                       (?: ' . $qstr_regexp . ' | (?>[^"\'\s]+))+
                      )
                      (?:\s+(.*))?
                    /xs', $template_tag, $match);
        $tag_command = $match[1];
        $tag_args = isset($match[2]) ? $match[2] : '';

        /* If the tag name matches a variable or section property definition,
           we simply process it. */
        if (preg_match('!^\$\w+(?>(\[\w+(\.\w+)?\])|((\.|->)\w+))*(?>\|@?\w+(:(?>' . $qstr_regexp . '|[^|]+))*)*$!', $tag_command) ||   // if a variable
            preg_match('!^#(\w+)#(?>\|@?\w+(:(?>' . $qstr_regexp . '|[^|]+))*)*$!', $tag_command)     ||  // or a configuration variable
            preg_match('!^%\w+\.\w+%(?>\|@?\w+(:(?>' . $qstr_regexp . '|[^|]+))*)*$!', $tag_command)) {    // or a section property
            settype($tag_command, 'array');
            $this->_parse_vars_props($tag_command);
            return "<?php echo $tag_command[0]; ?>\n";
        }

        switch ($tag_command) {
            case 'include':
                return $this->_compile_include_tag($tag_args);

            case 'if':
                return $this->_compile_if_tag($tag_args);

            case 'else':
                return '<?php else: ?>';

            case 'elseif':
                return $this->_compile_if_tag($tag_args, true);

            case '/if':
                return '<?php endif; ?>';

            case 'capture':
                return '<?php ob_start(); ?>';
                
            case '/capture':
                return '<?php $this->assign("return", ob_get_contents()); ob_end_clean(); ?>';
                
            case 'ldelim':
                return $this->left_delimiter;

            case 'rdelim':
                return $this->right_delimiter;

            case 'section':
                array_push($this->_sectionelse_stack, false);
                return $this->_compile_section_start($tag_args);

            case 'sectionelse':
                $this->_sectionelse_stack[count($this->_sectionelse_stack)-1] = true;
                return "<?php endfor; else: ?>";

            case '/section':
                if (array_pop($this->_sectionelse_stack))
                    return "<?php endif; ?>";
                else
                    return "<?php endfor; endif; ?>";

            case 'config_load':
                return $this->_compile_config_load_tag($tag_args);

            case 'strip':
            case '/strip':
                return $this->left_delimiter.$tag_command.$this->right_delimiter;

            case 'literal':
                list (,$literal_block) = each($this->_literal_blocks);
                $this->_current_line_no += substr_count($literal_block, "\n");
                return "<?php echo '".str_replace("'","\'",$literal_block)."'; ?>\n";

            case 'php':
                if ($this->security && !$this->security_settings['PHP_TAGS']) {
                    $this->_syntax_error("(secure mode) php tags not permitted", E_USER_WARNING);
                    return;
                }
                list (,$php_block) = each($this->_php_blocks);
                $this->_current_line_no += substr_count($php_block, "\n");
                return '<?php '.$php_block.' ?>';
                
            case 'insert':
                return $this->_compile_insert_tag($tag_args);

            default:
                if (isset($this->compiler_funcs[$tag_command])) {
                    return $this->_compile_compiler_tag($tag_command, $tag_args);
                } else if (isset($this->custom_funcs[$tag_command])) {
                    return $this->_compile_custom_tag($tag_command, $tag_args);
                } else {
                    $this->_syntax_error("unknown tag - '$tag_command'", E_USER_WARNING);
                    return;
                }
        }
    }


/*======================================================================*\
    Function: _compile_compiler_tag
    Purpose:  compile the custom compiler tag
\*======================================================================*/
    function _compile_compiler_tag($tag_command, $tag_args)
    {
        $function = $this->compiler_funcs[$tag_command];

        if (!function_exists($function)) {
            $this->_syntax_error("compiler function '$tag_command' is not implemented", E_USER_WARNING);
            return;
        }

        return '<?php' . $function($tag_args, $this) . ' ?>';
    }


/*======================================================================*\
    Function: _compile_custom_tag
    Purpose:  compile custom function tag
\*======================================================================*/
    function _compile_custom_tag($tag_command, $tag_args)
    {
        $function = $this->custom_funcs[$tag_command];

        if (!function_exists($function)) {
            $this->_syntax_error("custom function '$tag_command' is not implemented", E_USER_WARNING);
            return;
        }

        $attrs = $this->_parse_attrs($tag_args);
        foreach ($attrs as $arg_name => $arg_value) {
            if (is_bool($arg_value))
                $arg_value = $arg_value ? 'true' : 'false';
            $arg_list[] = "'$arg_name' => $arg_value";
        }

        return "<?php $function(array(".implode(',', (array)$arg_list)."), \$this); if(\$this->_extract) { extract(\$this->_tpl_vars); \$this->_extract=false; } ?>";
    }


/*======================================================================*\
    Function: _compile_insert_tag
    Purpose:  Compile {insert ...} tag
\*======================================================================*/
    function _compile_insert_tag($tag_args)
    {
        $attrs = $this->_parse_attrs($tag_args);
        $name = substr($attrs['name'], 1, -1);

        if (empty($name)) {
            $this->_syntax_error("missing insert name");
        }

        foreach ($attrs as $arg_name => $arg_value) {
            if (is_bool($arg_value))
                $arg_value = $arg_value ? 'true' : 'false';
            $arg_list[] = "'$arg_name' => $arg_value";
        }

        return "<?php echo _smarty_insert_handler(array(".implode(', ', (array)$arg_list)."), \$this->caching, \$this->_smarty_md5); ?>\n";
    }   
 

/*======================================================================*\
    Function: _compile_config_load_tag
    Purpose:  Compile {config_load ...} tag
\*======================================================================*/
    function _compile_config_load_tag($tag_args)
    {
        $attrs = $this->_parse_attrs($tag_args);

        if (empty($attrs['file'])) {
            $this->_syntax_error("missing 'file' attribute in config_load tag");
        }

        if (empty($attrs['section'])) {
            $attrs['section'] = 'null';
        }

        $scope = $this->_dequote($attrs['scope']);
        if (!empty($scope)) {
            if ($scope != 'local' &&
                $scope != 'parent' &&
                $scope != 'global') {
                $this->_syntax_error("invalid 'scope' attribute value");
            }
        } else {
            if (!empty($attrs['global']) && $attrs['global'])
                $scope = 'parent';
            else
                $scope = 'local';
        }

        $output  = '<?php $this->_config_load(' . $attrs['file'] . ', ' . $attrs['section'] . ", '$scope'); ?>";

        return $output;
    }


/*======================================================================*\
    Function: _compile_include_tag
    Purpose:  Compile {include ...} tag
\*======================================================================*/
    function _compile_include_tag($tag_args)
    {
        $attrs = $this->_parse_attrs($tag_args);
                
        if (empty($attrs['file'])) {
            $this->_syntax_error("missing 'file' attribute in include tag");
        }

        foreach ($attrs as $arg_name => $arg_value) {
            if ($arg_name == 'file') {
                $include_file = $arg_value;
                continue;
            }
            if (is_bool($arg_value))
                $arg_value = $arg_value ? 'true' : 'false';
            $arg_list[] = "'$arg_name' => $arg_value";
        }

        return  "<?php " .
            "\$_smarty_tpl_vars = \$this->_tpl_vars;\n" .
            "\$this->_smarty_include(".$include_file.", array(".implode(',', (array)$arg_list)."));\n" .
            "\$this->_tpl_vars = \$_smarty_tpl_vars;\n" .
            "unset(\$_smarty_tpl_vars); ?>";
    }


/*======================================================================*\
    Function: _compile_section_start
    Purpose:  Compile {section ...} tag
\*======================================================================*/
    function _compile_section_start($tag_args)
    {
        $attrs = $this->_parse_attrs($tag_args);

        $output = "<?php ";
        $section_name = $attrs['name'];
        if (empty($section_name)) {
            $this->_syntax_error("missing section name");
        }

        $output .= "if (isset(\$this->_sections[$section_name])) unset(\$this->_sections[$section_name]);\n";
        $section_props = "\$this->_sections[$section_name]['properties']";

        foreach ($attrs as $attr_name => $attr_value) {
            switch ($attr_name) {
                case 'loop':
                    $output .= "{$section_props}['loop'] = is_array($attr_value) ? count($attr_value) : max(0, (int)$attr_value);\n";
                    break;

                case 'show':
                    if (is_bool($attr_value))
                        $show_attr_value = $attr_value ? 'true' : 'false';
                    else
                        $show_attr_value = "(bool)$attr_value";
                    $output .= "{$section_props}['show'] = $show_attr_value;\n";
                    break;

                case 'name':
                    $output .= "{$section_props}['$attr_name'] = $attr_value;\n";
                    break;

                case 'max':
                case 'start':
                    $output .= "{$section_props}['$attr_name'] = (int)$attr_value;\n";
                    break;

                case 'step':
                    $output .= "{$section_props}['$attr_name'] = ((int)$attr_value) == 0 ? 1 : (int)$attr_value;\n";
                    break;

                default:
                    $this->_syntax_error("unknown section attribute - '$attr_name'");
                    break;
            }
        }

        if (!isset($attrs['show']))
            $output .= "{$section_props}['show'] = true;\n";

        if (!isset($attrs['loop']))
            $output .= "{$section_props}['loop'] = 1;\n";

        if (!isset($attrs['max']))
            $output .= "{$section_props}['max'] = {$section_props}['loop'];\n";
        else
            $output .= "if ({$section_props}['max'] < 0)\n" .
                       "    {$section_props}['max'] = {$section_props}['loop'];\n";   

        if (!isset($attrs['step']))
            $output .= "{$section_props}['step'] = 1;\n";

        if (!isset($attrs['start']))
            $output .= "{$section_props}['start'] = {$section_props}['step'] > 0 ? 0 : {$section_props}['loop']-1;\n";
        else {
            $output .= "if ({$section_props}['start'] < 0)\n" .
                       "    {$section_props}['start'] = max({$section_props}['step'] > 0 ? 0 : -1, {$section_props}['loop'] + {$section_props}['start']);\n" .
                       "else\n" .
                       "    {$section_props}['start'] = min({$section_props}['start'], {$section_props}['step'] > 0 ? {$section_props}['loop'] : {$section_props}['loop']-1);\n";
        }

        $output .= "if ({$section_props}['show']) {\n" .
                   "    {$section_props}['total'] = min(ceil(({$section_props}['step'] > 0 ? {$section_props}['loop'] - {$section_props}['start'] : {$section_props}['start']+1)/abs({$section_props}['step'])), {$section_props}['max']);\n" .
                   "    if ({$section_props}['total'] == 0)\n" .
                   "        {$section_props}['show'] = false;\n" .
                   "} else\n" .
                   "    {$section_props}['total'] = 0;\n";

        $output .= "if ({$section_props}['show']):\n";
        $output .= "
            for ({$section_props}['index'] = {$section_props}['start'], {$section_props}['iteration'] = 1;
                 {$section_props}['iteration'] <= {$section_props}['total'];
                 {$section_props}['index'] += {$section_props}['step'], {$section_props}['iteration']++):\n";
        $output .= "{$section_props}['rownum'] = {$section_props}['iteration'];\n";
        $output .= "{$section_props}['index_prev'] = {$section_props}['index'] - {$section_props}['step'];\n";
        $output .= "{$section_props}['index_next'] = {$section_props}['index'] + {$section_props}['step'];\n";
        $output .= "{$section_props}['first']      = ({$section_props}['iteration'] == 1);\n";
        $output .= "{$section_props}['last']       = ({$section_props}['iteration'] == {$section_props}['total']);\n";

        $output .= "?>";

        return $output;
    }


/*======================================================================*\
    Function: _compile_if_tag
    Purpose:  Compile {if ...} tag
\*======================================================================*/
    function _compile_if_tag($tag_args, $elseif = false)
    {
        /* Tokenize args for 'if' tag. */
        preg_match_all('/(?:
                         "[^"\\\\]*(?:\\\\.[^"\\\\]*)*"         | # match all double quoted strings allowed escaped double quotes
                         \'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'     | # match all single quoted strings allowed escaped single quotes
                         [(),]                                  | # match parentheses and commas
                         [^\s(),]+                                # match any other token that is not any of the above
                        )/x', $tag_args, $match);
        $tokens = $match[0];

        $this->_parse_vars_props($tokens);

        $is_arg_stack = array();

        for ($i = 0; $i < count($tokens); $i++) {
                        
            $token = &$tokens[$i];
            switch ($token) {
                case 'eq':
                    $token = '==';
                    break;

                case 'ne':
                case 'neq':
                    $token = '!=';
                    break;

                case 'lt':
                    $token = '<';
                    break;

                case 'le':
                case 'lte':
                    $token = '<=';
                    break;

                case 'gt':
                    $token = '>';
                    break;

                case 'ge':
                case 'gte':
                    $token = '>=';
                    break;

                case 'and':
                    $token = '&&';
                    break;

                case 'or':
                    $token = '||';
                    break;

                case 'not':
                    $token = '!';
                    break;

                case 'mod':
                    $token = '%';
                    break;

                case '(':
                    array_push($is_arg_stack, $i);
                    break;

                case 'is':
                    /* If last token was a ')', we operate on the parenthesized
                       expression. The start of the expression is on the stack.
                       Otherwise, we operate on the last encountered token. */
                    if ($tokens[$i-1] == ')')
                        $is_arg_start = array_pop($is_arg_stack);
                    else
                        $is_arg_start = $i-1;
                    /* Construct the argument for 'is' expression, so it knows
                       what to operate on. */
                    $is_arg = implode(' ', array_slice($tokens, $is_arg_start, $i - $is_arg_start));

                    /* Pass all tokens from next one until the end to the
                       'is' expression parsing function. The function will
                       return modified tokens, where the first one is the result
                       of the 'is' expression and the rest are the tokens it
                       didn't touch. */
                    $new_tokens = $this->_parse_is_expr($is_arg, array_slice($tokens, $i+1));

                    /* Replace the old tokens with the new ones. */
                    array_splice($tokens, $is_arg_start, count($tokens), $new_tokens);

                    /* Adjust argument start so that it won't change from the
                       current position for the next iteration. */
                    $i = $is_arg_start;
                    break;
                    
                default:
                    if($this->security &&
                       $tokens[$i+1] == '(' &&
                       preg_match('!^[a-zA-Z_]\w+$!', $tokens[$i]) &&
                       !in_array($tokens[$i], $this->security_settings['IF_FUNCS'])) {
                        $this->_syntax_error("(secure mode) '".$tokens[$i]."' not allowed in if statement");                
                    }
                    break;
            }
        }

        if ($elseif)
            return '<?php elseif ('.implode(' ', $tokens).'): ?>';
        else
            return '<?php if ('.implode(' ', $tokens).'): ?>';
    }


/*======================================================================*\
    Function: _parse_is_expr
    Purpose:  Parse is expression
\*======================================================================*/
    function _parse_is_expr($is_arg, $tokens)
    {
        $expr_end = 0;

        if (($first_token = array_shift($tokens)) == 'not') {
            $negate_expr = true;
            $expr_type = array_shift($tokens);
        } else
            $expr_type = $first_token;

        switch ($expr_type) {
            case 'even':
                if ($tokens[$expr_end] == 'by') {
                    $expr_end++;
                    $expr_arg = $tokens[$expr_end++];
                    $expr = "!(($is_arg / $expr_arg) % $expr_arg)";
                }
                else
                    $expr = "!($is_arg % 2)";
                break;

            case 'odd':
                if ($tokens[$expr_end] == 'by') {
                    $expr_end++;
                    $expr_arg = $tokens[$expr_end++];
                    $expr = "(($is_arg / $expr_arg) % $expr_arg)";
                }
                else
                    $expr = "($is_arg % 2)";
                break;

            case 'div':
                if ($tokens[$expr_end] == 'by') {
                    $expr_end++;
                    $expr_arg = $tokens[$expr_end++];
                    $expr = "!($is_arg % $expr_arg)";
                } else {
                    $this->_syntax_error("expecting 'by' after 'div'");
                }
                break;

            default:
                $this->_syntax_error("unknown 'is' expression - '$expr_type'");
                break;
        }

        if ($negate_expr) {
            $expr = "!($expr)";
        }

        array_splice($tokens, 0, $expr_end, $expr);

        return $tokens;
    }


/*======================================================================*\
    Function: _parse_attrs
    Purpose:  Parse attribute string
\*======================================================================*/
    function _parse_attrs($tag_args, $quote = true)
    {
        /* Tokenize tag attributes. */
        preg_match_all('/(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"       | 
                          \'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'     | (?>[^"\'=\s]+)
                         )+ |
                         [=]
                        /x', $tag_args, $match);
        $tokens       = $match[0];
        $var_delims   = array('$', '#', '%');

        $attrs = array();
        /* Parse state:
            0 - expecting attribute name
            1 - expecting '='
            2 - expecting attribute value (not '=') */
        $state = 0;

        foreach ($tokens as $token) {
            switch ($state) {
                case 0:
                    /* If the token is a valid identifier, we set attribute name
                       and go to state 1. */
                    if (preg_match('!^\w+$!', $token)) {
                        $attr_name = $token;
                        $state = 1;
                    } else
                        $this->_syntax_error("invalid attribute name - '$token'");
                    break;

                case 1:
                    /* If the token is '=', then we go to state 2. */
                    if ($token == '=') {
                        $state = 2;
                    } else
                        $this->_syntax_error("expecting '=' after attribute name");
                    break;

                case 2:
                    /* If token is not '=', we set the attribute value and go to
                       state 0. */
                    if ($token != '=') {
                        /* We booleanize the token if it's a non-quoted possible
                           boolean value. */
                        if (preg_match('!^(on|yes|true)$!', $token))
                            $token = true;
                        else if (preg_match('!^(off|no|false)$!', $token))
                            $token = false;
                        /* If the token is not variable (doesn't start with
                           '$', '#', or '%') and not enclosed in single or
                           double quotes we single-quote it. */
                        else if ($quote && !in_array($token{0}, $var_delims) &&
                                 !(($token{0} == '"' || $token[0] == "'") &&
                                 $token{strlen($token)-1} == $token{0}))
                            $token = '"'.$token.'"';

                        $attrs[$attr_name] = $token;
                        $state = 0;
                    } else
                        $this->_syntax_error("'=' cannot be an attribute value");
                    break;
            }
        }

        $this->_parse_vars_props($attrs);

        return $attrs;
    }

    
/*======================================================================*\
    Function: _parse_vars_props
    Purpose:  compile variables and section properties tokens into
              PHP code
\*======================================================================*/
    function _parse_vars_props(&$tokens)
    {        
        $qstr_regexp = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';

        $var_exprs = preg_grep('!^\$\w+(?>(\[\w+(\.\w+)?\])|((\.|->)\w+))*(?>\|@?\w+(:(?>' .  $qstr_regexp . '|[^|]+))*)*$!', $tokens);
        $conf_var_exprs = preg_grep('!^#(\w+)#(?>\|@?\w+(:(?>' . $qstr_regexp . '|[^|]+))*)*$!', $tokens);
        $sect_prop_exprs = preg_grep('!^%\w+\.\w+%(?>\|@?\w+(:(?>' .  $qstr_regexp .  '|[^|]+))*)*$!', $tokens);

        if (count($var_exprs)) {
            foreach ($var_exprs as $expr_index => $var_expr) {
                $tokens[$expr_index] = $this->_parse_var($var_expr);
            }
        }

        if (count($conf_var_exprs)) {
            foreach ($conf_var_exprs as $expr_index => $var_expr) {
                $tokens[$expr_index] = $this->_parse_conf_var($var_expr);
            }
        }

        if (count($sect_prop_exprs)) {
            foreach ($sect_prop_exprs as $expr_index => $section_prop_expr) {
                $tokens[$expr_index] = $this->_parse_section_prop($section_prop_expr);
            }
        }
    }


/*======================================================================*\
    Function: _parse_var
    Purpose:  parse variable expression into PHP code
\*======================================================================*/
    function _parse_var($var_expr)
    {
        $parts = explode('|', substr($var_expr, 1), 2);
        $var_ref = $parts[0];
        $modifiers = isset($parts[1]) ? $parts[1] : '';

        preg_match_all('!\[\w+(\.\w+)?\]|(->|\.)\w+|^\w+!', $var_ref, $match);
        $indexes = $match[0];
        $var_name = array_shift($indexes);

        $output = "\$this->_tpl_vars['$var_name']";

        foreach ($indexes as $index) {
            if ($index{0} == '[') {
                $parts = explode('.', substr($index, 1, -1));
                $section = $parts[0];
                $section_prop = isset($parts[1]) ? $parts[1] : 'index';
                $output .= "[\$this->_sections['$section']['properties']['$section_prop']]";
            } else if ($index{0} == '.') {
                $output .= "['" . substr($index, 1) . "']";
            } else {
                $output .= $index;
            }
        }

        $this->_parse_modifiers($output, $modifiers);

        return $output;
    }


/*======================================================================*\
    Function: _parse_conf_var
    Purpose:  parse configuration variable expression into PHP code
\*======================================================================*/
    function _parse_conf_var($conf_var_expr)
    {
        $parts = explode('|', $conf_var_expr, 2);
        $var_ref = $parts[0];
        $modifiers = isset($parts[1]) ? $parts[1] : '';

        $var_name = substr($var_ref, 1, -1);

        $output = "\$this->_config[0]['$var_name']";

        $this->_parse_modifiers($output, $modifiers);

        return $output;
    }


/*======================================================================*\
    Function: _parse_section_prop
    Purpose:  parse section property expression into PHP code
\*======================================================================*/
    function _parse_section_prop($section_prop_expr)
    {
        $parts = explode('|', $section_prop_expr, 2);
        $var_ref = $parts[0];
        $modifiers = isset($parts[1]) ? $parts[1] : '';

        preg_match('!%(\w+)\.(\w+)%!', $var_ref, $match);
        $section_name = $match[1];
        $prop_name = $match[2];

        $output = "\$this->_sections['$section_name']['properties']['$prop_name']";

        $this->_parse_modifiers($output, $modifiers);

        return $output;
    }


/*======================================================================*\
    Function: _parse_modifiers
    Purpose:  parse modifier chain into PHP code
\*======================================================================*/
    function _parse_modifiers(&$output, $modifier_string)
    {
        $qstr_regexp = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';
        preg_match_all('!\|(@?\w+)((?>:(?:'. $qstr_regexp . '|[^|]+))*)!', '|' . $modifier_string, $match);
        list(, $modifiers, $modifier_arg_strings) = $match;

        for ($i = 0; $i < count($modifiers); $i++) {
            $modifier_name = $modifiers[$i];
            preg_match_all('!:(' . $qstr_regexp . '|[^:]+)!', $modifier_arg_strings[$i], $match);
            $modifier_args = $match[1];

            if ($modifier_name{0} == '@') {
                $map_array = 'false';
                $modifier_name = substr($modifier_name, 1);
            } else
                $map_array = 'true';

            /*
             * First we lookup the modifier function name in the registered
             * modifiers table.
             */
            $mod_func_name = $this->custom_mods[$modifier_name];

            /*
             * If we don't find that modifier there, we assume it's just a PHP
             * function name.
             */
            if (!isset($mod_func_name)) {
                if ($this->security && !in_array($modifier_name, $this->security_settings['MODIFIER_FUNCS'])) {
                    $this->_syntax_error("(secure mode) modifier '$modifier_name' is not allowed", E_USER_WARNING);
                    continue;
                } else {
                    $mod_func_name = $modifier_name;
                }
            }

            if (!function_exists($mod_func_name)) {
                $this->_syntax_error("modifier '$modifier_name' is not implemented", E_USER_WARNING);
                continue;
            }

            $this->_parse_vars_props($modifier_args);

            if (count($modifier_args) > 0)
                $modifier_args = ', '.implode(', ', $modifier_args);
            else
                $modifier_args = '';

            $output = "_smarty_mod_handler('$mod_func_name', $map_array, $output$modifier_args)";
        }
    }


/*======================================================================*\
    Function: _syntax_error
    Purpose:  display Smarty syntax error
\*======================================================================*/
    function _syntax_error($error_msg, $error_type = E_USER_ERROR)
    {
        trigger_error("Smarty: [in " . $this->_current_file . " line " .
                      $this->_current_line_no . "]: syntax error: $error_msg", $error_type);
    }
}

/* vim: set et: */

?>
