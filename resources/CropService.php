<?php

namespace ImageFocus;

use Tribe\Project\Theme\Image_Sizes;

/**
 * The class responsible for cropping the attachments
 *
 * Class CropService
 * @package ImageFocus
 */
class CropService {
	protected $attachment = [];
	protected $imageSizes = [];
	protected $focusPoint = [ 'x' => 50, 'y' => 50 ];

	/**
	 * Crop the image on base of the focus point
	 *
	 * @param $attachmentId
	 * @param $focusPoint
	 *
	 * @return bool
	 */
	public function crop( $attachmentId, $focusPoint ) {
		// Set all the cropping data
		$this->setCropData( $attachmentId, $focusPoint );
		$this->cropAttachment();
	}

	/**
	 * Save the focal point data to the DB
	 * Skips the actual image creation to speed up importing
	 * @param $focusPoint
	 */
	public function setFocusPointData( $attachmentId, $focusPoint ) {
		$this->attachment['id'] = $attachmentId;
		$this->setFocusPoint( $focusPoint );
		$this->saveFocusPointToDB();
	}

	/**
	 * Set all crop data
	 *
	 * @param $attachmentId
	 * @param $focusPoint
	 */
	protected function setCropData( $attachmentId, $focusPoint ) {
		$this->getAttachment( $attachmentId );
		$this->getImageSizes();
		$this->setFocusPoint( $focusPoint );
		$this->saveFocusPointToDB();
	}

	/**
	 * Get all the image sizes excluding the ones that don't need cropping
	 *
	 * @return $this
	 */
	public function getImageSizes() {
		// Get all the default WordPress image Sizes
		foreach ( (array) get_intermediate_image_sizes() as $imageSize ) {
			if ( in_array( $imageSize, [ 'thumbnail', 'medium', 'medium_large', 'large' ], true )
			     && get_option( "{$imageSize}_crop" )
			) {
				$this->imageSizes[ $imageSize ] = [
					'width'  => (int) get_option( "{$imageSize}_size_w" ),
					'height' => (int) get_option( "{$imageSize}_size_h" ),
					'crop'   => (bool) get_option( "{$imageSize}_crop" ),
					'ratio'  => (float) get_option( "{$imageSize}_size_w" ) / (int) get_option( "{$imageSize}_size_h" )
				];
			}
		}

		// Get all the custom set image Sizes
		foreach ( (array) wp_get_additional_image_sizes() as $key => $imageSize ) {
			if ( $imageSize['crop'] ) {
				$this->imageSizes[ $key ] = $imageSize;

				$width  = (float) $imageSize['width'];
				$height = $imageSize['height'];

				if ( $height > 0 ) {
					$ratio = $width / $height;
				} else {
					// if the height is zero, we need to use the original image's aspect ratio
					$ratio = $this->attachment['ratio'];
					// also calculate the height for later use
					$this->imageSizes[ $key ]['height'] = round( $width / $ratio, 0 );
				}

				$this->imageSizes[ $key ]['ratio'] = $ratio;
			}
		}

		return $this;
	}

	/**
	 *  Return the src array of the attachment image containing url, width & height
	 *
	 * @param $attachmentId
	 *
	 * @return $this
	 */
	protected function getAttachment( $attachmentId ) {
		$attachment = wp_get_attachment_image_src( $attachmentId, 'full' );

		$this->attachment = [
			'id'     => $attachmentId,
			'src'    => (string) $attachment[0],
			'width'  => (int) $attachment[1],
			'height' => (int) $attachment[2],
			'ratio'  => (float) $attachment[1] / $attachment[2]
		];

		return $this;
	}

	/**
	 * Set the focuspoint for the crop
	 *
	 * @param $focusPoint
	 *
	 * @return $this
	 */
	protected function setFocusPoint( $focusPoint ) {
		if ( $focusPoint ) {
			$this->focusPoint = $focusPoint;
		}

		return $this;
	}

	/**
	 * Put the focuspoint in the post meta of the attachment post
	 */
	protected function saveFocusPointToDB() {
		update_post_meta( $this->attachment['id'], 'focus_point', $this->focusPoint );
	}

	/**
	 * Crop the actual attachment
	 */
	protected function cropAttachment() {
		$image_meta = get_post_meta( $this->attachment['id'], '_wp_attachment_metadata', true );

		foreach ( $this->imageSizes as $size_name => $imageSize ) {
			$meta = tribe_project()->container()[ 'tachyon.meta' ];

			$new_size = $meta->dimension_params(
				[
					$this->attachment['width'],
					$this->attachment['height'],
				],
				[
					$imageSize['width'],
					$imageSize['height'],
				],
				$this->focusPoint
			);

			$image_meta['sizes'][$size_name]['file'] = $new_size;
		}

		wp_update_attachment_metadata( $this->attachment['id'], '_wp_attachment_metadata' );
	}

	/**
	 * Get the file destination based on the attachment in the argument
	 *
	 * @param $imageSize
	 *
	 * @return mixed
	 */
	protected function getImageFilePath( $imageSize ) {
		// Get the path to the WordPress upload directory
		$uploadDir = wp_upload_dir()['basedir'] . '/';

		// Get the attachment name
		$attachedFile      = get_post_meta( $this->attachment['id'], '_wp_attached_file', true );
		$attachment        = pathinfo( $attachedFile )['filename'];
		$croppedAttachment = $attachment . '-' . $imageSize['width'] . 'x' . $imageSize['height'];

		// Add the image size to the the name of the attachment
		$fileName = str_replace( $attachment, $croppedAttachment, $attachedFile );

		return $uploadDir . $fileName;
	}

	/**
	 * Remove the old attachment
	 *
	 * @param $file
	 */
	protected function removeOldImage( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}

	/**
	 * Calculate the all of the positions necessary to crop the image and crop it.
	 *
	 * @param $imageSize
	 * @param $imageFilePath
	 *
	 * @return $this
	 */
	protected function cropImage( $imageSize, $imageFilePath ) {
		return $this;
		// Gather all dimension
		$dimensions = [ 'x' => [], 'y' => [] ];
		$directions = [ 'x' => 'width', 'y' => 'height' ];

		// Define the correction the image needs to keep the same ratio after the cropping has taken place
		$cropCorrection = [
			'x' => $imageSize['ratio'] / $this->attachment['ratio'],
			'y' => $this->attachment['ratio'] / $imageSize['ratio']
		];

		// Check all the cropping values
		foreach ( $dimensions as $axis => $dimension ) {

			// Get the center position
			$dimensions[ $axis ]['center'] = $this->focusPoint[ $axis ] / 100 * $this->attachment[ $directions[ $axis ] ];
			// Get the starting position and let's correct the crop ratio
			$dimensions[ $axis ]['start'] = $dimensions[ $axis ]['center'] - $this->attachment[ $directions[ $axis ] ] * $cropCorrection[ $axis ] / 2;
			// Get the ending position and let's correct the crop ratio
			$dimensions[ $axis ]['end'] = $dimensions[ $axis ]['center'] + $this->attachment[ $directions[ $axis ] ] * $cropCorrection[ $axis ] / 2;

			// Is the start position lower than 0? That's not possible so let's correct it
			if ( $dimensions[ $axis ]['start'] < 0 ) {
				// Adjust the ending, but don't make it higher than the image itself
				$dimensions[ $axis ]['end'] = min( $dimensions[ $axis ]['end'] - $dimensions[ $axis ]['start'],
					$this->attachment[ $directions[ $axis ] ] );
				// Adjust the start, but don't make it lower than 0
				$dimensions[ $axis ]['start'] = max( $dimensions[ $axis ]['start'] - $dimensions[ $axis ]['start'], 0 );
			}

			// Is the start position higher than the total image size? That's not possible so let's correct it
			if ( $dimensions[ $axis ]['end'] > $this->attachment[ $directions[ $axis ] ] ) {
				// Adjust the start, but don't make it lower than 0
				$dimensions[ $axis ]['start'] = max( $dimensions[ $axis ]['start'] + $this->attachment[ $directions[ $axis ] ] - $dimensions[ $axis ]['end'],
					0 );
				// Adjust the ending, but don't make it higher than the image itself
				$dimensions[ $axis ]['end'] = min( $dimensions[ $axis ]['end'] + $this->attachment[ $directions[ $axis ] ] - $dimensions[ $axis ]['end'],
					$this->attachment[ $directions[ $axis ] ] );
			}
		}

		// Excecute the WordPress image crop function
		wp_crop_image( $this->attachment['id'],
			$dimensions['x']['start'],
			$dimensions['y']['start'],
			$dimensions['x']['end'] - $dimensions['x']['start'],
			$dimensions['y']['end'] - $dimensions['y']['start'],
			$imageSize['width'],
			$imageSize['height'],
			false,
			$imageFilePath
		);

		return $this;
	}
}
