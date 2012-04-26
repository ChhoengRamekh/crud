<?php

class Crud
{
	/**
	 * The primary key for the model on the database table.
	 *
	 * @var string
	 */
	public static $key = 'id';

	/**
	 * The name of the table associated with the model.
	 * If left null, the table name will become the the plural of the class name: user => users
	 *
	 * @var string
	 */
	protected static $table = null;

	/**
	 * The name of the database connection that should be used for the model.
	 *
	 * @var string
	 */
	protected static $connection = null;

	/**
	 * The name of the sequence associated with the model.
	 *
	 * @var string
	 */
	protected static $sequence = null;

	/**
	 * Indicates if the model has update and creation timestamps.
	 *
	 * @var bool
	 */
	protected static $timestamps = true;

	/**
	 * @var  array  $rules  Validation rules for model attributes
	 */
	protected static $rules = array();

	/**
	 * Attributes for the model
	 */
	protected $attributes = array();

	/**
	 * Indicates if the model exists in the database.
	 *
	 * @var  bool
	 */
	protected $exists = false;

	/**
	 * @var  validation object
	 */
	protected $validation = null;

	/**
	 * Create a new Crud model instance.
	 *
	 * @param  array  $attributes
	 * @param  bool   $exists
	 * @return void
	 */
	public function __construct($attributes = array(), $exists = null)
	{
		// Hydrate our model
		$this->fill((array) $attributes);

		// Set exists flag. If none is provided, but we have got a primary key
		// it must exist in the database
		$this->exists = ($exists === null) ? array_key_exists(static::$key, $attributes) : (bool) $exists;
	}

	/**
	 * Save the model instance to the database.
	 *
	 * @return bool
	 */
	public function save()
	{
		// first check if we want timestamps as this will append to attributes
		if (static::$timestamps)
		{
			$this->timestamp();
		}

		// now we grab the attributes
		$attributes = $this->attributes();

		// run validation if rules are set
		if ( ! empty(static::$rules))
		{
			$validated = $this->run_validation($attributes);

			if ( ! $validated )
			{
				return false;
			}
		}

		// prep attribute values after validation is done
		$attributes = $this->prep_attributes($attributes);

		// If the model exists, we only need to update it in the database, and the update
		// will be considered successful if there is one affected row returned from the
		// fluent query instance. We'll set the where condition automatically.
		if ($this->exists)
		{
			// grab the key and remove it from the attributes array
			$key = $attributes[static::$key];
			unset($attributes[static::$key]);

			$query = $this->query()->where(static::$key, '=', $key);

			list($query, $attributes) = $this->before_update($query, $attributes);

			$result = $query->update($attributes);

			$result = $this->after_update($result);

			$result = $query->update($attributes) === 1;
		}

		// If the model does not exist, we will insert the record and retrieve the last
		// insert ID that is associated with the model. If the ID returned is numeric
		// then we can consider the insert successful.
		else
		{
			$query = $this->query();

			list($query, $attributes) = $this->before_insert($query, $attributes);

			$result = $this->query()->insert_get_id($attributes, static::$sequence);

			$result = $this->after_insert($result);

			$this->exists = (bool) $result;
		}

		return $result;
	}

	/**
	 * Delete a model from the datatabase
	 *
	 * @return  bool
	 */
	public function delete()
	{
		$query = $this->query()->where(static::$key, '=', $this->{static::$key});

		$query = $this->before_delete($query);
		$result = $query->delete();
		$result = $this->after_delete($result);

		return $result;
	}

	/**
	 * Get the name of the table associated with the model.
	 *
	 * @return string
	 */
	public function table()
	{
		return static::$table ?: strtolower(Str::plural(class_basename($this)));
	}

	/**
	 * Hydrate the model with an array of attributes.
	 *
	 * @param  array  $attributes
	 * @return Model
	 */
	public function fill($attributes = array())
	{
		foreach ($attributes as $key => $value)
		{
			$this->attributes[$key] = $value;
		}

		return $this;
	}

	/**
	 * Get all the attributes of the model.
	 *
	 * @return array
	 */
	public function attributes()
	{
		return $this->attributes;
	}

	/**
	 * Find a model by its primary key.
	 *
	 * @param  string  $id
	 * @param  array   $columns
	 * @return Model
	 */
	public static function find($id, $columns = array('*'))
	{
		$model = new static;

		$query = $model->query()->where(static::$key, '=', $id);

		list($query, $columns) = $this->before_find($query, $columns);

		$result = $query->take(1)->get($columns);

		$result = $this->after_find($results);

		if (count($result) > 0)
		{
			return $model->exists(true)
			             ->fill($result);
		}

		return null;
	}

	/**
	 * Get all of the models in the database.
	 *
	 * @return array
	 */
	public static function all()
	{
		$results = with(new static)->query()->get();
		$models  = array();

		foreach ($results as $result)
		{
			$models[] = new static($result, true);
		}

		return $models;
	}

	/**
	 * Set or Determine if the model is new or not
	 *
	 * @return object|bool
	 */
	protected function exists($exists = null)
	{
		if ($exists === null)
		{
			return $this->exists;
		}

		$this->exists = (bool) $exists;

		return $this;
	}

	/**
	 * Get a new fluent query builder instance for the model.
	 *
	 * @return Query
	 */
	public function query()
	{
		return DB::connection(static::$connection)->table($this->table());
	}

	/**
	 * Returns the a validation object for the model.
	 *
	 * @return  object  Validation object
	 */
	public function validation()
	{
		return $this->validation;
	}

	/**
	 * Set the update and creation timestamps on the model.
	 *
	 * @return void
	 */
	protected function timestamp()
	{
		$this->updated_at = time();

		if ( ! $this->exists) $this->created_at = $this->updated_at;
	}

	/**
	 * Gets called before find() is executed to modify the query
	 * Must return an array of the query object and columns array($query, $columns)
	 *
	 * @return  array  $query object and $columns array
	 */
	protected function before_find($query, $columns)
	{
		return array($query, $columns);
	}

	/**
	 * Gets call after the find() query is exectuted to modify the result
	 * Must return a proper result
	 *
	 * @return  object  Model object result
	 */
	protected function after_find($result)
	{
		return $result;
	}

	/**
	 * Gets called before insert() is executed to modify the query
	 * Must return an array of the query object and columns array($query, $columns)
	 *
	 * @return  array  $query object and $columns array
	 */
	protected function before_insert($query, $columns)
	{
		return array($query, $columns);
	}

	/**
	 * Gets call after the insert() query is exectuted to modify the result
	 * Must return a proper result
	 *
	 * @return  object  Model object result
	 */
	protected function after_insert($result)
	{
		return $result;
	}

	/**
	 * Gets called before update() is executed to modify the query
	 * Must return an array of the query object and columns array($query, $columns)
	 *
	 * @return  array  $query object and $columns array
	 */
	protected function before_update($query, $columns)
	{
		return array($query, $columns);
	}

	/**
	 * Gets call after the update() query is exectuted to modify the result
	 * Must return a proper result
	 *
	 * @return  object  Model object result
	 */
	protected function after_update($result)
	{
		return $result;
	}

	/**
	 * Gets called before delete() is executed to modify the query
	 * Must return an array of the query object and columns array($query, $columns)
	 *
	 * @return  array  $query object and $columns array
	 */
	protected function before_delete($query)
	{
		return $query;
	}

	/**
	 * Gets call after the delete() query is exectuted to modify the result
	 * Must return a proper result
	 *
	 * @return  object  Model object result
	 */
	protected function after_delete($result)
	{
		return $result;
	}

	/**
	 * Run validation
	 *
	 * @return bool
	 */
	protected function run_validation($attributes)
	{
		$attributes = $this->before_validation($attributes);

		$this->validation = Validator::make($attributes, static::$rules);

		$result = $this->after_validation($this->validation->fails());

		return ($result) ? false : true;
	}

	/**
	 * Gets called before the validation is ran.
	 *
	 * @param   array  $data  The validation data
	 * @return  array
	 */
	protected function before_validation($data)
	{
		return $data;
	}

	/**
	 * Called right after the validation is ran.
	 *
	 * @param   bool  $result  Validation result
	 * @return  bool
	 */
	protected function after_validation($result)
	{
		return $result;
	}

	/**
	 * Called right after validation before inserting/updating to the database
	 *
	 * @param   array  $attributes  attribute array
	 * @return  array
	 */
	protected function prep_attributes($attributes)
	{
		return $attributes;
	}

	/**
	 * Dynamically retrieve the value of an attribute.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function __get($key)
	{
		if (array_key_exists($key, $this->attributes))
		{
			return $this->attributes[$key];
		}
	}

	/**
	 * Dynamically set the value of an attribute.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function __set($key, $value)
	{
		$this->attributes[$key] = $value;
	}

	/**
	 * Dynamically check if an attribute is set.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function __isset($key)
	{
		return isset($this->attributes[$key]);
	}

	/**
	 * Dynamically unset an attribute.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function __unset($key)
	{
		unset($this->{$key});
	}

}
