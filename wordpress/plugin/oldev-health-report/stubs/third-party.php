<?php
/**
 * Minimal signatures of optional FluentCRM classes for static analysis.
 *
 * @package OldevHealthReport
 */

namespace FluentCrm\App\Models {

	/**
	 * Tag model.
	 */
	class Tag {

		/**
		 * Tag id.
		 *
		 * @var int
		 */
		public $id;

		/**
		 * Finds or creates a tag.
		 *
		 * @param array<string,string> $find   Lookup.
		 * @param array<string,string> $values Values.
		 * @return self
		 */
		public static function firstOrCreate( array $find, array $values = array() ) {
			return new self();
		}
	}

	/**
	 * Contact model.
	 */
	class Subscriber {

		/**
		 * Starts a query.
		 *
		 * @param string $column Column.
		 * @param string $value  Value.
		 * @return self
		 */
		public static function where( string $column, string $value ) {
			return new self();
		}

		/**
		 * Returns the first row.
		 *
		 * @return self|null
		 */
		public function first() {
			return $this;
		}

		/**
		 * Deletes the row.
		 *
		 * @return bool
		 */
		public function delete() {
			return true;
		}
	}
}

namespace FluentCrm\App\Api\Classes {

	/**
	 * Contacts API.
	 */
	class Contacts {

		/**
		 * Creates or updates a contact.
		 *
		 * @param array<string,mixed> $data Contact.
		 * @return object|false
		 */
		public function createOrUpdate( array $data ) {
			return false;
		}
	}
}

namespace {

	/**
	 * FluentCRM API accessor.
	 *
	 * @param string $key API name.
	 * @return \FluentCrm\App\Api\Classes\Contacts
	 */
	function FluentCrmApi( string $key ) { // phpcs:ignore
		return new \FluentCrm\App\Api\Classes\Contacts();
	}
}
