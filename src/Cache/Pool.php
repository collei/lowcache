<?php
namespace Collei\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Collei\Values\ArrayValue;
use Collei\Values\ObjectValue;
use Throwable;

/**
 * CacheItemPoolInterface generates CacheItemInterface objects.
 *
 * The primary purpose of Cache\CacheItemPoolInterface is to accept a key from
 * the Calling Library and return the associated Cache\CacheItemInterface object.
 * It is also the primary point of interaction with the entire cache collection.
 * All configuration and initialization of the Pool is left up to an
 * Implementing Library.
 */
class Pool implements CacheItemPoolInterface
{
	/**
	 * @var string
	 */
	public const DIC_FILE_SPEC = 'CACHE.DIC';

	/**
	 * @var array
	 */
	protected $dictionary;

	/**
	 * @var string
	 */
	protected $dictionaryFile;

	/**
	 * @var string
	 */
	protected $path;

	/**
	 * @var string
	 */
	private $hardSaving = true;

	/**
	 * Initializes the pool.
	 *
	 * @param string $path
	 * @return void
	 */
	public function __construct(string $path)
	{
		$this->path = $path;
		$this->dictionary = [];

		$this->dictionaryFile = $this->makeFilePath(self::DIC_FILE_SPEC);

		$this->loadDictionary();
	}

	/**
	 * Craft cache item file path from the given cache key.
	 *
	 * @param string $key
	 * @return string
	 */
	protected function makeFilePath(string $key)
	{
		return $this->path . DIRECTORY_SEPARATOR . $key;
	}

	/**
	 * Initialize the internal item dictionary.
	 *
	 * @return void
	 */
	protected function loadDictionary()
	{
		if (empty ($this->dictionaryFile)) {
			return;
		}

		if (is_dir($this->path) && file_exists($this->dictionaryFile)) {
			if ($dic = ArrayValue::loadFromFile($this->dictionaryFile)) {
				$this->dictionary = $dic->get();
			}
		}
	}

	/**
	 * Store the internal item dictionary.
	 *
	 * @return void
	 */
	protected function saveDictionary()
	{
		if (empty ($this->path)) {
			return;
		}

		if (is_dir($this->path)) {
			$dicfile = $this->makeFilePath(self::DIC_FILE_SPEC);

			$dic = ArrayValue::make($this->dictionary);

			$dic->saveTo($dicfile);
		}
	}

	/**
	 * Returns a Cache Item representing the specified key.
	 *
	 * This method must always return a CacheItemInterface object, even in case of
	 * a cache miss. It MUST NOT return null.
	 *
	 * @param string $key
	 *   The key for which to return the corresponding Cache Item.
	 *
	 * @throws InvalidArgumentException
	 *   If the $key string is not a legal value a \Psr\Cache\InvalidArgumentException
	 *   MUST be thrown.
	 *
	 * @return CacheItemInterface
	 *   The corresponding Cache Item.
	 */
	public function getItem($key)
	{
		if (array_key_exists($key, $this->dictionary)) {
			$itempath = $this->dictionary[$key];

			if (file_exists($itempath)) {
				if ($item = ObjectValue::loadFromFile($itempath)) {
					if ($thing = $item->get()) {
						if ($thing->isHit()) {
							return $thing;
						}
					}
				}
			}
		}

		$item = new Item(null, $key);

		$this->save($item);

		return $item->get();
	}

	/**
	 * Returns a traversable set of cache items.
	 *
	 * @param string[] $keys
	 *   An indexed array of keys of items to retrieve.
	 *
	 * @throws InvalidArgumentException
	 *   If any of the keys in $keys are not a legal value a \Psr\Cache\InvalidArgumentException
	 *   MUST be thrown.
	 *
	 * @return array|\Traversable
	 *   A traversable collection of Cache Items keyed by the cache keys of
	 *   each item. A Cache item will be returned for each key, even if that
	 *   key is not found. However, if no keys are specified then an empty
	 *   traversable MUST be returned instead.
	 */
	public function getItems(array $keys = array())
	{
		if (empty($keys)) {
			return [];
		}

		$items = [];

		$this->hardSaving = false;

		foreach ($keys as $key) {
			$keys[$key] = $this->getItem($key);
		}

		$this->hardSaving = true;

		$this->saveDictionary();

		return $items;
	}

	/**
	 * Confirms if the cache contains specified cache item.
	 *
	 * Note: This method MAY avoid retrieving the cached value for performance reasons.
	 * This could result in a race condition with CacheItemInterface::get(). To avoid
	 * such situation use CacheItemInterface::isHit() instead.
	 *
	 * @param string $key
	 *   The key for which to check existence.
	 *
	 * @throws InvalidArgumentException
	 *   If the $key string is not a legal value a \Psr\Cache\InvalidArgumentException
	 *   MUST be thrown.
	 *
	 * @return bool
	 *   True if item exists in the cache, false otherwise.
	 */
	public function hasItem($key)
	{
		if (array_key_exists($key, $this->dictionary)) {
			return file_exists($this->dictionary[$key]);
		}

		return false;
	}

	/**
	 * Deletes all items in the pool.
	 *
	 * @return bool
	 *   True if the pool was successfully cleared. False if there was an error.
	 */
	public function clear()
	{
		try {
			foreach ($this->dictionary as $key => $filename) {
				if (file_exists($filename)) {
					unlink($filename);
				}
			}

			unlink($this->makeFilePath(self::DIC_FILE_SPEC));

			unset($this->dictionary);

			$this->dictionary = [];
		} catch (Throwable $t) {
			return false;
		}

		return true;
	}

	/**
	 * Removes the item from the pool.
	 *
	 * @param string $key
	 *   The key to delete.
	 *
	 * @throws InvalidArgumentException
	 *   If the $key string is not a legal value a \Psr\Cache\InvalidArgumentException
	 *   MUST be thrown.
	 *
	 * @return bool
	 *   True if the item was successfully removed. False if there was an error.
	 */
	public function deleteItem($key)
	{
		try {
			if (array_key_exists($key, $this->dictionary)) {
				$filename = $this->dictionary[$key];

				if (file_exists($filename)) {
					unlink($filename);
				}

				unset($this->dictionary[$key]);

				$this->saveDictionary();
			}
		} catch (Throwable $t) {
			return false;
		}

		return true;
	}

	/**
	 * Removes multiple items from the pool.
	 *
	 * @param string[] $keys
	 *   An array of keys that should be removed from the pool.
	 *
	 * @throws InvalidArgumentException
	 *   If any of the keys in $keys are not a legal value a \Psr\Cache\InvalidArgumentException
	 *   MUST be thrown.
	 *
	 * @return bool
	 *   True if the items were successfully removed. False if there was an error.
	 */
	public function deleteItems(array $keys)
	{
		try {
			foreach ($keys as $key) {
				$filename = $this->dictionary[$key];

				if (file_exists($filename)) {
					unlink($filename);
				}

				unset($this->dictionary[$key]);
			}

			$this->saveDictionary();
		} catch (Throwable $t) {
			return false;
		}

		return true;
	}

	/**
	 * Persists a cache item immediately.
	 *
	 * @param CacheItemInterface $item
	 *   The cache item to save.
	 *
	 * @return bool
	 *   True if the item was successfully persisted. False if there was an error.
	 */
	public function save(CacheItemInterface $item)
	{
		try {
			$key = $item->getKey();

			$this->dictionary[$key] = $filename = $this->makeFilePath($key);

			$cacheItem = ObjectValue::make($item, $key);

			$cacheItem->saveTo($filename);
		} catch (Throwable $t) {
			return false;
		}

		if ($this->hardSaving) {
			$this->saveDictionary();
		}

		return true;
	}

	/**
	 * Sets a cache item to be persisted later.
	 *
	 * @param CacheItemInterface $item
	 *   The cache item to save.
	 *
	 * @return bool
	 *   False if the item could not be queued or if a commit was attempted and failed. True otherwise.
	 */
	public function saveDeferred(CacheItemInterface $item)
	{
		$this->save($item);

		return bool;
	}

	/**
	 * Persists any deferred cache items.
	 *
	 * @return bool
	 *   True if all not-yet-saved items were successfully saved or there were none. False otherwise.
	 */
	public function commit()
	{
		return true;
	}
}
