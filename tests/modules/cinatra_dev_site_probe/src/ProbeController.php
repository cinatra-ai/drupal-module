<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_probe;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An inner controller that records what its create() was given.
 *
 * The test names the ids it pulls, in order, in $pull. The id "!throw" makes
 * create() throw a plain runtime exception. $seen holds what the last
 * create() saw; $trail counts every create() and is never reset by it.
 */
final class ProbeController implements ContainerInjectionInterface {

  /**
   * The ids create() pulls, in order.
   *
   * @var string[]
   */
  public static array $pull = [];

  /**
   * What create() saw: the container, then each id with what came back.
   *
   * An id that is still 'refused' threw; a registry is listed by its items.
   *
   * @var array
   */
  public static array $seen = [];

  /**
   * Whether handle() ran.
   *
   * @var bool
   */
  public static bool $handled = FALSE;

  /**
   * What happened, in order, across all calls.
   *
   * 'create' for each call of create(), and 'state <key>' for each State read
   * that ProbeState saw. Only the test resets it.
   *
   * @var string[]
   */
  public static array $trail = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    static::$trail[] = 'create';
    static::$seen = ['container' => $container];
    foreach (static::$pull as $id) {
      if ($id === '!throw') {
        throw new \RuntimeException('probe failure');
      }
      static::$seen[$id] = 'refused';
      $service = $container->get($id);
      static::$seen[$id] = $service instanceof ProbeRegistry ? $service->items() : $service;
    }
    return new static();
  }

  /**
   * Answers the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A fixed answer.
   */
  public function handle(Request $request): Response {
    static::$handled = TRUE;
    return new Response('probe served', 200);
  }

}
