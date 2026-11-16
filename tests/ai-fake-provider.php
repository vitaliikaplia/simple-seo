<?php
/**
 * A provider of the AI Client of WordPress for the tests: configured while $GLOBALS['sseo_ai_configured']
 * says so, with one model that takes every option but those in $GLOBALS['sseo_ai_without'] and answers
 * what $GLOBALS['sseo_ai_answer'] gives for the prompt (a string, a callable that makes one, or a
 * Throwable to throw). Each prompt is kept in $GLOBALS['sseo_ai_prompts'] with the model's
 * configuration and the files it was shown (type and bytes), so the tests see what was asked.
 */

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

if ( class_exists( 'Simple_SEO_Test_AI_Provider' ) ) {
	return;
}

final class Simple_SEO_Test_AI_Availability implements ProviderAvailabilityInterface {
	public function isConfigured(): bool {
		return ! empty( $GLOBALS['sseo_ai_configured'] );
	}
}

final class Simple_SEO_Test_AI_Directory implements ModelMetadataDirectoryInterface {
	public static function metadata(): ModelMetadata {
		return new ModelMetadata(
			'sseo-test-model',
			'Simple SEO test model',
			array( CapabilityEnum::textGeneration() ),
			array_map(
				static fn( OptionEnum $option ): SupportedOption => new SupportedOption( $option ),
				// $GLOBALS['sseo_ai_without']: options the model does not take, as a model without JSON output.
				array_values( array_filter( OptionEnum::cases(), static fn( OptionEnum $option ): bool => ! in_array( $option->value, (array) ( $GLOBALS['sseo_ai_without'] ?? array() ), true ) ) )
			)
		);
	}

	public function listModelMetadata(): array {
		return array( self::metadata() );
	}

	public function hasModelMetadata( string $modelId ): bool {
		return 'sseo-test-model' === $modelId;
	}

	public function getModelMetadata( string $modelId ): ModelMetadata {
		return self::metadata();
	}
}

final class Simple_SEO_Test_AI_Model implements ModelInterface, TextGenerationModelInterface {
	private ModelConfig $config;

	public function __construct( private ModelMetadata $model, private ProviderMetadata $provider ) {
		$this->config = new ModelConfig();
	}

	public function metadata(): ModelMetadata {
		return $this->model;
	}

	public function providerMetadata(): ProviderMetadata {
		return $this->provider;
	}

	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	public function getConfig(): ModelConfig {
		return $this->config;
	}

	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$text  = '';
		$files = array();
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				$text .= (string) $part->getText();
				if ( null !== $part->getFile() ) {
					$files[] = array( $part->getFile()->getMimeType(), (string) base64_decode( (string) $part->getFile()->getBase64Data() ) );
				}
			}
		}
		$GLOBALS['sseo_ai_prompts'][] = array(
			'text'   => $text,
			'files'  => $files,
			'system' => (string) $this->config->getSystemInstruction(),
			'schema' => $this->config->getOutputSchema(),
			'mime'   => $this->config->getOutputMimeType(),
		);
		$answer = $GLOBALS['sseo_ai_answer'] ?? '';
		$answer = is_callable( $answer ) ? $answer( $text, $this->config ) : $answer;
		if ( $answer instanceof Throwable ) {
			throw $answer;
		}

		return new GenerativeAiResult(
			'sseo-test-' . count( $GLOBALS['sseo_ai_prompts'] ),
			array( new Candidate( new ModelMessage( array( new MessagePart( (string) $answer ) ) ), FinishReasonEnum::stop() ) ),
			new TokenUsage( 1, 1, 2 ),
			$this->provider,
			$this->model
		);
	}

	public function streamGenerateTextResult( array $prompt ): Generator {
		yield $this->generateTextResult( $prompt );
	}
}

final class Simple_SEO_Test_AI_Provider extends AbstractProvider {
	protected static function createModel( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ): ModelInterface {
		return new Simple_SEO_Test_AI_Model( $modelMetadata, $providerMetadata );
	}

	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'sseo-test', 'Simple SEO test', ProviderTypeEnum::cloud() );
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new Simple_SEO_Test_AI_Availability();
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new Simple_SEO_Test_AI_Directory();
	}
}
