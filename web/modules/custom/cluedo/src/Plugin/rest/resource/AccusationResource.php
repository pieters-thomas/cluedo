<?php

namespace Drupal\cluedo\Plugin\rest\resource;

use Drupal;
use Drupal\cluedo\Services\GameManager;
use Drupal\cluedo\Services\Repository;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\rest\Annotation\RestResource;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource that processes a suggestion and returns if/how disproved
 *
 * @RestResource(
 *   id = "accusation_resource",
 *   label = "Cluedo Accusation Resource",
 *   uri_paths = {
 *   "create" = "/api/cluedo/accuse"
 *   }
 * )
 */
class AccusationResource extends ResourceBase
{
  private const SUCCESS_MESSAGE = "Proficiat, je hebt de game opgelost! Wij met Calibrate leren iemand met zoveel kwaliteiten als jij beter kennen. Laat hier je e-mail adres achter en we contacteren je voor je volgende quest!";
  private const FAILURE_MESSAGE = 'Helaas, dit was niet het juiste antwoord.';
  private const ON_GAME_OVER_MESSAGE = 'Deze zaak is reeds afgesloten.';
  private const QR_CODE_PATH = 'modules/custom/cluedo/assets/frame.png';

  private Repository $repo;
  private GameManager $gameManager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, Repository $repo, GameManager $gameManager)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->repo = $repo;
    $this->gameManager = $gameManager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): ResourceBase|AccusationResource|static
  {
    /**
     * @var Repository $repo
     */
    $repo = $container->get('cluedo.repository');

    /** @var GameManager $gameManager */
    $gameManager = $container->get('cluedo.game_manager');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')?->get('custom_rest'),
      $repo,
      $gameManager,
    );
  }


  /**
   * @throws EntityStorageException
   * @throws Exception
   */
  public function post($data): ResourceResponse
  {
    $game = $this->repo->fetchGame(Drupal::request()->get('key'));

    if (!$game) {
      return new ResourceResponse("Spel niet gevonden");
    }

    if ($game->isGameOver()) {
      return new ResourceResponse(self::ON_GAME_OVER_MESSAGE);
    }

    //Updates node in database with game_over = true
    $this->gameManager->endGame($game);

    //check if accusation matches solution

    $answerArray = [
      'kamer'=> htmlspecialchars($data['kamer'], ENT_QUOTES),
      'wapen'=> htmlspecialchars($data['wapen'], ENT_QUOTES),
      'karakter'=> htmlspecialchars($data['karakter'], ENT_QUOTES),
    ];

    $solutionArray = [
      'kamer' => (string) $game->getSolution()->getRoom()->getNodeId(),
      'wapen' => (string)$game->getSolution()->getWeapon()->getNodeId(),
      'karakter' => (string) $game->getSolution()->getSuspect()->getNodeId(),
    ];

    $isCorrect = ($answerArray === $solutionArray);

    $type = pathinfo(self::QR_CODE_PATH, PATHINFO_EXTENSION);
    $data = file_get_contents(self::QR_CODE_PATH);
    $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);

    return new ResourceResponse([
        'correct' => $isCorrect,
        'message' => $isCorrect? self::SUCCESS_MESSAGE: self::FAILURE_MESSAGE,
        'answer' => $answerArray,
        'solution' => $solutionArray,
        'easterEgg' => $isCorrect? $base64: null
    ]);

  }

}
