<?
function UserDefined(array $t_args, array $inputs, array $outputs)
{
    // Class name randomly generated.
    $className = generate_name("User");

    // The string used to wrap encodings within the code.
    $e = "@@";

    // Lines of code for various functions, as strings. Each block of code can
    // contain various encodings of expression that require type information,
    // such as type declaraction, return statements, etc.
    $initialized = $t_args['initialized'];  // Array of initializer list code
    $constructor = $t_args['constructor'];  // Block of code for constructor
    $AddItem     = $t_args['AddItem'];      // Block of code for AddItem
    $AddState    = $t_args['AddState'];     // Block of code for AddState
    $GetResult   = $t_args['GetResult'];    // Block of code for GetResult

    // Other arguments detailing function header, fields, types, etc. Each value
    // of $params and $header is a string, an array specifying a type, or null
    // only for $params. In the first case, the string is either verbatim C++
    // code or a typedef that appears in $typedefs, in which case it will then
    // be converted to a datatype. In the second case, the arrays are converted
    // to actual datatypes as well.
    $fields    = $t_args['fields'];     // Class fields, name => type
    $constants = $t_args['constants'];  // Static members, name => value
    $params    = $t_args['params'];     // Header for AddItem, name => type
    $other     = $t_args['other'];      // Name of AddState argument
    $typedefs  = $t_args['typedefs'];   // Names of typedefs of $inputs
    $types     = $t_args['types'];      // Templated type structures
    $gets      = $t_args['gets'];       // Calls to get on types in $typedefs
    $result    = $t_args['result'];     // Return statements structures
    $return    = $t_args['return'];     // Type of result - json, tuple, etc
    $header    = $t_args['header'];     // Header for GetResult, name => type
    $templates = $t_args['templates'];  // Template arguments passed by user.

    // Setting output type.
    array_set_index($outputs, 0, lookupType("base::JSON"));

    // Constant values are converted from queries on types to literal values.
    foreach ($constants as &$ref) {
        if (is_array($ref)) {
            $property = array_keys($ref)[0];
            $type = array_get_index($inputs, $ref[$property]);
            $ref = $type->get($property);
        } else if (is_identifier($ref)) {
            $ref = $templates[strval($ref)];
        }
    }

    // Converting $types into actual types now that constants are evaluated.
    foreach ($types as &$typeref)
        Convert_Type($typeref, $constants, $typedefs);
    unset($typeref);

    // Typedefs are converted in $header and $params only. Other code is left as
    // is due to the presence of typedefs in C++. These are converted so that
    // they can be used in the following assertions and construction of the code.
    foreach ($params as &$value)
        if (is_string($value) && in_array($value, $typedefs))
            $value = array_get_index($inputs, array_search($value, $typedefs));
        else if (is_array($value))
            Convert_Type($value, $constants, $typedefs);
    unset($value);

    foreach ($header as &$value)
        if (is_string($value) && in_array($value, $typedefs))
            $value = array_get_index($inputs, array_search($value, $typedefs));
        else if (is_array($value))
            Convert_Type($value, $constants, $typedefs);
    unset($value);

    // Checks that the number of inputs is both sufficient and necessary.
    grokit_assert(count($inputs) >= count($typedefs),
                  "There were not enough inputs to match [types].");
    grokit_assert(count($inputs) >= count($params),
                  "There were not enough inputs to match AddItem.");
    grokit_assert(count($inputs) <= max(count($params), count($typedefs)),
                  "There were superfluous inputs given.");

    // Checks that inputs specified in the GLA match those received.
    for ($counter = 0; $counter < count($params); $counter++) {
        $param = array_get_index($params, $counter);
        $input = array_get_index($inputs, $counter);
        if (is_datatype($param)) {
            grokit_assert(canConvert($input, $param),
                          "Types given and specified are incompatible:\n" .
                          "Given: " . strval($input) . "\n" .
                          "Received: " . strval($param));
        } else if (is_null($param)) {
            array_set_index($params, $counter, $input);
        }
    }

    // Finishes construction of $params using $inputs keys and extra values.
    for ($counter = count($params); $counter < count($inputs); $counter++)
        $params[] = $inputs[$counter];
    $params = array_combine(array_keys($inputs), $params);

    // Double-checks that the number of outputs is correct. This should have
    // been already checked in R.
    grokit_assert(count($outputs) == count($header),
                  "Incorrect number of outputs received.");

    // Sets $outputs types according to what was given by the user.
    for ($counter = 0; $counter < count($outputs); $counter++)
        array_set_index($outputs, $counter, array_get_index($header, $counter));

    // Code construction.

    // Constructs the initializer list, including the colon for a non-empty list
    foreach ($initialized as $name => $value)
        $initializerList[] = $name . '(' . $value . ')';
    if (count($initialized) > 0)
      $initializer = "\n    : " . implode(",\n      ", $initializerList) . ' ';
    else
      $initializer = '';

    // Type information is now inserted into the code.
    $functions = [$constructor, $AddItem, $AddState, $GetResult];

    for ($counter = 0; $counter < count($types); $counter++) {
        $type = strval($types[$counter]);
        $replace = $e . "type" . $counter . $e;
        $functions = str_replace($replace, $type, $functions);
        $fields    = str_replace($replace, $type, $fields);
    }

    $constructor = $functions[0];
    $AddItem = $functions[1];
    $AddState = $functions[2];
    $GetResult = $functions[3];

    // The result statement for JSONs is now produced.
    if ($return == "json") {
        for ($counter = count($result) - 1; $counter >= 0; $counter--) {
            $GetResult = str_replace($e . 'result' . $counter . $e,
                                     ProduceResult(array_keys($outputs)[0],
                                                   $result[$counter]),
                                     $GetResult);
        }
    } else {
        for ($counter = count($result) - 1; $counter >= 0; $counter--) {
            $code = '';
            foreach (array_keys($outputs) as $counter => $key)
                $code .= $key . ' = ' . $result[$counter][$index] . ";\n";
            $replace = $e . 'result' . $counter . $e;
            $GetResult = str_replace($replace, $code, $GetResult);
        }
    }

    // Basic return values.
    $sys_headers = array_merge($t_args['sys.headers'], ['armadillo']);
    $user_headers = $t_args['user.headers'];
    $lib_headers = array_merge($t_args['lib.headers'], ['statistics/ArmaJson']);
?>

<?  foreach ($typedefs as $counter => $type) { ?>
using <?=$type?> = <?=array_get_index($inputs, $counter)?>;
<?  } ?>

using namespace std;
using namespace arma;

class <?=$className?>;

class <?=$className?> {
 private:
<?  foreach ($constants as $name => $value) { ?>
  const constexpr static auto <?=$name?> = <?=$value?>;
<?  } ?>
<?  foreach ($fields as $name => $type) { ?>
  <?=$type?> <?=$name?>;
<?  } ?>

 public:
  <?=$className?>() <?=$initializer, $constructor?>

  void AddItem(<?=const_typed_ref_args($params)?>) <?=$AddItem?>

  void AddState(<?=$className?> <?=$other?>) <?=$AddState?>

  void GetResult(<?=typed_ref_args($outputs)?>) <?=$GetResult?>

};

<?
    return array(
        'kind'           => 'GLA',
        'name'           => $className,
        'system_headers' => $sys_headers,
        'user_headers'   => $user_headers,
        'lib_headers'    => $lib_headers,
        'input'          => $inputs,
        'output'         => $outputs,
        'result_type'    => 'single',
    );
}

// This recursively descends through the given type structure and manually
// replaces symbols representing constant values with the literal value.
function Convert_Type(&$type, $constants, $types) {
    $name = $type["_name"];
    unset($type["_name"]);
    foreach ($type as &$value) {
        if (is_array($value))
            Convert_Type($value, $constants);
        else if (is_identifier($value))
            if (array_key_exists(strval($value), $constants))
                $value = $constants[strval($value)];
            else
                $value = array_get_index($inputs,
                                         array_search($value, $typedefs));
    }
    $type = lookupType($name, $type);
}
?>
