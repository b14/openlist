<?php
/**
 * Ting Object Ratings module
 */

/**
 * OpenList Module TingObjectRating
 *
 * Handle ratings of materials
 * @see OpenList::callModule()
 *
 */
class TingObjectRating extends Module {
  /**
   * Module version.
   * @ignore
   */
  public $version = 1;

  /**
   * The table.
   */
  private $table = 'm_tingobject_rating';

  /**
   * Abstract getEvents().
   * @ignore
   */
  public function getEvents() {
    return array(
      'createElement' => 'onElementCreated',
      'editElement' => 'onEditElement',
      'deleteElement' => 'onDeleteElement',
      // 'cron' => 'cron',
    );
  }

  /**
   * Get popular objects.
   *
   * @param string $month
   *   The month to retreive popular materials from in the format YYYYMM
   * @param string $libcode
   *   Optional library code, default is all libraries
   * @param int $limit
   *   Limit  number of materials to return (max 256)
   */
  public function getPopular($month, $libcode = FALSE, $limit = 10) {
    if ($limit > 256) {
      $limit = 256;
    }
    if ($limit < 0) {
      $limit = 1;
    }

    if (!empty($libcode)) {
      $libcode_where = '
  AND library_code = "@libcode"';
    }

    $last_month = date('Ym', mktime(0, 0, 0, substr($month, 4), 0, substr($month, 0, 4)));

    $result = DB::q('
SELECT object_id, COUNT(rating) * AVG(rating) AS score, AVG(rating) AS average
FROM !table
WHERE (
    created = %month
    OR created = %last_month
  ) ' . $libcode_where . '
GROUP BY
  created, object_id
HAVING
  AVG(rating) > 3
ORDER BY
  score DESC
LIMIT 0, !limit',
    array(
      '!table' => $this->table,
      '@libcode' => $libcode,
      '%month' => $month,
      '%last_month' => $last_month,
      '!limit' => $limit,
    ));

    $buffer = array();

    while ($row = $result->fetch_assoc()) {
      $buffer[] = $row;
    }

    return $buffer;
  }

  /**
   * Get suggestions, depending on a given object_id based on similar high ratings. (ADHR)
   *
   * @param string $object_id
   *   The object_id to fetch ADHR suggestions for.
   * @param string $owner
   *   Optionally exclude this owner from aggregated results.
   */
  public function getSuggestion($object_id, $owner = FALSE) {
    if ($owner !== FALSE) {
      $owner_where = '
  AND t2.owner != "@owner"';
    }

    $result = DB::q('
SELECT t2.object_id, COUNT(t2.object_id) AS counts
FROM !table t1 JOIN !table t2 ON (t2.owner = t1.owner AND t2.rating > 3)
WHERE
  t1.object_id = "@object_id"
  AND t1.rating = 5
  AND t2.object_id != t1.object_id' . $owner_where . '
GROUP BY
  t2.object_id
ORDER BY counts DESC
LIMIT 50
  ',
    array(
      '!table' => $this->table,
      '@object_id' => $object_id,
      '@owner' => $owner,
    ));

    $buffer = array();
    while ($row = $result->fetch_assoc()) {
      $buffer[] = $row;
    }

    // Force a positive test result.
    if (empty($buffer) && ENABLE_TEST_RESULTS) {
      $buffer[] = "870970-basis:22244566";
    }

    return $buffer;
  }

  /**
   * Get an object aggregated rating.
   *
   * @param string $object_id
   *   The object_id to fetch aggregated rating for.
   */
  public function getRating($object_id) {
    $result = DB::q('
SELECT AVG(rating) AS rating
FROM !table
WHERE
  object_id = "@object_id"
GROUP BY
  object_id
    ', array(
      '!table' => $this->table,
      '@object_id' => $object_id,
    ));

    $o = $result->fetch_object();

    return isset($o->rating) ? ( (float) $o->rating ) : 0;
  }

  /**
   * Get the aggregated rating of multiple objects.
   *
   * @param string $object_ids
   *   The object_ids to fetch aggregated rating for.
   */
  public function getRatings($object_ids = array()) {
    foreach ($object_ids as &$object_id) {
      $object_id = DB::$db->real_escape_string($object_id);
    }

    $result = DB::q('
SELECT object_id, AVG(rating) AS rating
FROM !table
WHERE
  object_id IN ("!object_ids")
GROUP BY
  object_id
    ', array(
      '!table' => $this->table,
      '!object_ids' => implode('", "', $object_ids),
    ));

    $buffer = array();
    while ($row = $result->fetch_assoc()) {
      $buffer[$row['object_id']] = (float) $row['rating'];
    }

    return $buffer;
  }

  /**
   * Get rated objects from a specific date.
   *
   * @ignore
   */
  public function getRated($date = FALSE) {

    $date_where = '';
    if ($date !== FALSE) {
      $date_where = '
WHERE
  created = %date
';
    }

    $result = DB::q('
SELECT object_id, AVG(rating) AS rating
FROM !table' . $date_where . '
GROUP BY
  object_id
ORDER BY
  rating DESC
    ', array(
      '!table' => $this->table,
      '%date' => $date,
    ));

    $buffer = array();
    while ($row = $result->fetch_assoc()) {
      $buffer[] = $row;
    }

    return $buffer;
  }

  /**
   * On element deleted.
   * @ignore
   */
  protected function onDeleteElement($element_id) {
    $result = DB::q('
SELECT e.data, l.owner
FROM elements e JOIN lists l ON (l.list_id = e.list_id)
WHERE
  e.element_id IN (?%element_id)
    ', array(
      '?%element_id' => $element_id,
    ));

    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $data = unserialize($row['data']);

        if ($data['type'] == 'ting_object'
            && isset($data['weight']) && is_numeric($data['weight'])) {
          DB::q('
DELETE FROM !table
WHERE
  object_id = "@object_id"
  AND owner = "@owner"
          ', array(
            '!table' => $this->table,
            '@object_id' => $data['value'],
            '@owner' => $row['owner'],
          ));
        }
      }

      return TRUE;
    }

    return FALSE;
  }

  /**
   * On element edited.
   * @ignore
   */
  protected function onEditElement($element_id, $data) {
    if ($data['type'] == 'ting_object'
        && isset($data['weight']) && is_numeric($data['weight'])) {
      $owner = DB::q('
SELECT l.owner
FROM lists l JOIN elements e ON (l.list_id = e.list_id)
WHERE e.element_id = %element_id', array('%element_id' => $element_id))->fetch_object()->owner;

      DB::q('
INSERT INTO !table
(owner, object_id, rating, created, library_code)
VALUES ("@owner", "@object_id", %rating, @date, "@library_code")
  ON DUPLICATE KEY UPDATE
    rating = %rating
      ', array(
        '!table' => $this->table,
        '@owner' => $owner,
        '@object_id' => $data['value'],
        '%rating' => $data['weight'],
        '@date' => date('Ym'),
        '@library_code' => $GLOBALS['library_code'],
      ));

      return TRUE;
    }

    return FALSE;
  }

  /**
   * On element created.
   * @ignore
   */
  protected function onElementCreated($element_id, $list_id, $data) {
    if ($data['type'] == 'ting_object'
        && isset($data['weight']) && is_numeric($data['weight'])) {
      $owner = DB::q('
SELECT owner
FROM lists
WHERE list_id = %list_id', array('%list_id' => $list_id))->fetch_object()->owner;

      DB::q('
INSERT INTO !table
(owner, object_id, rating, created, library_code)
VALUES ("@owner", "@object_id", %rating, @date, "@library_code")
  ON DUPLICATE KEY UPDATE
    rating = %rating
      ', array(
        '!table' => $this->table,
        '@owner' => $owner,
        '@object_id' => $data['value'],
        '%rating' => $data['weight'],
        '@date' => date('Ym'),
        '@library_code' => $GLOBALS['library_code'],
      ));

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Decrease the popularity.
   * @ignore
   */
  protected function cron($arg) {
    // This cron only triggers on an "hour" cron.
    if (in_array('hour', $arg)) {

      return TRUE;
    }
    return FALSE;
  }

  /**
   * Create the module table on install.
   * @ignore
   */
  protected function _install() {
    DB::q('
CREATE TABLE IF NOT EXISTS !table (
  owner varchar(128) NOT NULL,
  object_id char(20) NOT NULL,
  rating TINYINT NOT NULL,
  created VARCHAR(6) NOT NULL,
  library_code varchar(128) NOT NULL,
  PRIMARY KEY (owner, object_id),
  KEY owner_rating (owner, rating)
) ENGINE = InnoDB
    ', array('!table' => $this->table));

    return TRUE;
  }

  /**
   * Remove the module table on uninstall.
   * @ignore
   */
  protected function _uninstall() {
    DB::q('DROP TABLE IF EXISTS !table', array('!table' => $this->table));

    return TRUE;
  }
}

new TingObjectRating();
