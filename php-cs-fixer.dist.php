<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/workbench',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notName([
        '*.blade.php',
        '*Enum.php',
    ])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();

return $config
    ->setRules([
        // General rules from .editorconfig
        'encoding'    => true, // charset = utf-8
        'line_ending' => true, // end_of_line = lf

        // PHP specific rules from .editorconfig
        'array_indentation' => true,
        'array_syntax'      => [
            'syntax' => 'short',
        ],
        'binary_operator_spaces' => [
            'default'   => 'single_space',
            'operators' => [
                '=>' => 'align_single_space',
                '='  => 'align_single_space_minimal',
            ],
        ],
        'blank_line_after_namespace'   => true,
        'blank_line_after_opening_tag' => true, // ij_php_new_line_after_php_opening_tag = true
        'blank_line_before_statement'  => [
            'statements' => ['return', 'if', 'for', 'foreach', 'while', 'do', 'switch', 'try'], // ij_php_blank_lines_before_return_statement = 1
        ],
        'spaces_inside_parentheses'     => false, // вместо 'no_spaces_inside_parenthesis'
        'type_declaration_spaces'       => true,    // вместо 'function_typehint_space'
        'no_unneeded_braces'            => true,        // вместо 'no_unneeded_curly_braces'
        'braces_position'               => true,           // часть замены для 'braces'
        'single_space_around_construct' => true, // часть замены для 'braces'
        'control_structure_braces'      => true,      // часть замены для 'braces'
        'statement_indentation'         => true,      // часть замены для 'braces'
        'cast_spaces'                   => ['space' => 'single'], // ij_php_space_after_type_cast = true
        'class_attributes_separation'   => [
            'elements' => [
                'method' => 'one', // ij_php_blank_lines_around_method = 1
                // 'property' => 'one', // ij_php_blank_lines_around_field = 0
            ],
        ],
        'class_definition' => [
            'multi_line_extends_each_single_line' => true, // ij_php_extends_list_wrap = on_every_item
            'single_item_single_line'             => true,
            'single_line'                         => false, // ij_php_keep_simple_classes_in_one_line = false
        ],
        'concat_space'                            => ['spacing' => 'one'], // ij_php_concat_spaces = true
        'constant_case'                           => ['case' => 'lower'], // ij_php_lower_case_boolean_const = true, ij_php_lower_case_null_const = true
        'control_structure_continuation_position' => ['position' => 'same_line'], // ij_php_special_else_if_treatment = false
        'declare_equal_normalize'                 => false,
        'elseif'                                  => true, // ij_php_else_if_style = combine
        'function_declaration'                    => [
            'closure_function_spacing' => 'one', // ij_php_space_before_closure_left_parenthesis = true
            'closure_fn_spacing'       => 'none', // ij_php_space_before_closure_left_parenthesis = true
        ],
        // deprecated
        // 'function_typehint_space' => false,
        'include' => true,
        // 'increment_style' => ['style' => 'post'],
        'indentation_type'            => true,
        'linebreak_after_opening_tag' => true,
        'no_superfluous_phpdoc_tags'  => true,
        'lowercase_cast'              => true,
        'lowercase_keywords'          => true, // ij_php_lower_case_keywords = true
        'lowercase_static_reference'  => true,
        'magic_constant_casing'       => true,
        'magic_method_casing'         => true,
        'method_argument_space'       => [
            'on_multiline'                     => 'ignore', // ij_php_method_parameters_wrap = on_every_item
            'keep_multiple_spaces_after_comma' => false, // ij_php_space_after_comma = true
        ],
        'native_function_casing'             => true,
        'no_blank_lines_after_class_opening' => true, // ij_php_blank_lines_after_class_header = 0
        'no_blank_lines_after_phpdoc'        => false,
        'no_closing_tag'                     => true,
        'no_empty_phpdoc'                    => true,
        'no_empty_statement'                 => true,
        'no_extra_blank_lines'               => [
            'tokens' => [
                'extra',
                'throw',
                'use',
                'break',
                'continue',
                'curly_brace_block',
                'parenthesis_brace_block',
                'return',
                'square_brace_block',
                'switch',
                'case',
                'default',
            ],
        ],
        'no_leading_import_slash'                     => true,
        'no_leading_namespace_whitespace'             => true,
        'no_mixed_echo_print'                         => ['use' => 'echo'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_short_bool_cast'                          => true,
        'no_singleline_whitespace_before_semicolons'  => true,
        'no_spaces_after_function_name'               => true, // ij_php_space_before_method_call_parentheses = false, ij_php_space_before_method_parentheses = false
        'no_spaces_around_offset'                     => true,
        // deprecated
        // 'no_spaces_inside_parenthesis' => true, // ij_php_spaces_within_parentheses = false
        'no_trailing_comma_in_singleline'   => true,
        'no_trailing_whitespace'            => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_unneeded_control_parentheses'   => true,
        // deprecated
        // 'no_unneeded_curly_braces' => true,
        'no_unused_imports'                   => true,
        'no_whitespace_before_comma_in_array' => true, // ij_php_space_before_comma = false
        'no_whitespace_in_blank_line'         => true,
        'normalize_index_brace'               => true,
        'not_operator_with_successor_space'   => false, // ij_php_space_after_unary_not = false
        'object_operator_without_whitespace'  => true,
        // 'ordered_imports' => ['sort_algorithm' => 'alpha'], // ij_php_import_sorting = alphabetic
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'method_public_abstract_static',
                'method_protected_abstract_static',
                'method_private_abstract_static',
                'method_public_abstract',
                'method_protected_abstract',
                'method_private_abstract',
                'construct',
                'phpunit',
                'method_public',
                'method_protected',
                'method_private',
                'magic',
                'destruct',
            ],
        ],
        'phpdoc_align' => [
            'tags' => ['param'], // ij_php_align_phpdoc_param_names = true
        ],
        'phpdoc_indent'                => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_no_access'             => true,
        'phpdoc_no_package'            => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_order'                 => [
            'order' => [
                'param',
                'return',
                'throws',
            ],
        ], // ij_php_sort_phpdoc_elements = true
        'phpdoc_scalar'                  => true,
        'phpdoc_separation'              => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary'                 => false,
        'phpdoc_trim'                    => true,
        'phpdoc_types'                   => true,
        'phpdoc_var_without_name'        => true,
        'return_type_declaration'        => ['space_before' => 'none'], // ij_php_space_before_colon_in_return_type = false
        //'self_accessor' => true,
        'short_scalar_cast'        => true,
        'simplified_null_return'   => false,
        'single_blank_line_at_eof' => true,
        // deprecated
        // 'single_blank_line_before_namespace' => true, // ij_php_blank_lines_before_package = 1
        'single_class_element_per_statement' => true,
        'single_import_per_statement'        => true,
        'single_line_after_imports'          => true, // ij_php_blank_lines_after_imports = 1
        'single_line_comment_style'          => [
            'comment_types' => ['hash'],
        ],
        'single_quote'                   => false,
        'space_after_semicolon'          => true,
        'standardize_not_equals'         => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space'              => true,
        'ternary_operator_spaces'        => true,
        'trailing_comma_in_multiline'    => ['elements' => ['arrays']], // ij_php_comma_after_last_array_element = true
        'trim_array_spaces'              => true,
        'unary_operator_spaces'          => true, // ij_php_spaces_around_unary_operator = false
        'modifier_keywords'              => [
            'elements' => ['property', 'method', 'const'],
        ],
        'whitespace_after_comma_in_array' => true, // ij_php_space_after_comma = true
    ])
    ->setIndent('    ') // indent_size = 4
    ->setLineEnding("\n") // end_of_line = lf
    ->setFinder($finder);
