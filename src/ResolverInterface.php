<?php

namespace Faktory;

/**
 * ResolverInterface
 *
 * Implement this to use a custom class class loader; that is, dynamically
 * load job class and construct an instance given it's enqueued class name.
 */
interface ResolverInterface
{
	/**
	 * @param string $class
	 * @return Class
	 */
	public function resolveClass($class);
}
