<?php
require_once 'vendor/autoload.php';
use \jamesiarmes\PhpEws\Client;

use \jamesiarmes\PhpEws\Request\FindItemType;
use \jamesiarmes\PhpEws\Request\GetItemType;
use \jamesiarmes\PhpEws\Request\UpdateItemType;

use \jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfBaseFolderIdsType;
use \jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfBaseItemIdsType;
use \jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfPathsToElementType;

use \jamesiarmes\PhpEws\Enumeration\BodyTypeResponseType;
use \jamesiarmes\PhpEws\Enumeration\ContainmentComparisonType;
use \jamesiarmes\PhpEws\Enumeration\ContainmentModeType;
use \jamesiarmes\PhpEws\Enumeration\DefaultShapeNamesType;
use \jamesiarmes\PhpEws\Enumeration\DistinguishedFolderIdNameType;
use \jamesiarmes\PhpEws\Enumeration\MapiPropertyTypeType;
use \jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use \jamesiarmes\PhpEws\Enumeration\UnindexedFieldURIType;

use \jamesiarmes\PhpEws\Type\AndType;
use \jamesiarmes\PhpEws\Type\ConstantValueType;
use \jamesiarmes\PhpEws\Type\ContainsExpressionType;
use \jamesiarmes\PhpEws\Type\DistinguishedFolderIdType;
use \jamesiarmes\PhpEws\Type\FieldURIOrConstantType;
use \jamesiarmes\PhpEws\Type\IndexedPageViewType;
use \jamesiarmes\PhpEws\Type\IsEqualToType;
use \jamesiarmes\PhpEws\Type\IsGreaterThanOrEqualToType;
use \jamesiarmes\PhpEws\Type\IsLessThanOrEqualToType;
use \jamesiarmes\PhpEws\Type\ItemChangeType;
use \jamesiarmes\PhpEws\Type\ItemIdType;
use \jamesiarmes\PhpEws\Enumeration\ItemQueryTraversalType;
use \jamesiarmes\PhpEws\Type\ItemResponseShapeType;
use \jamesiarmes\PhpEws\Type\MessageType;
use \jamesiarmes\PhpEws\Type\OrType;
use \jamesiarmes\PhpEws\Type\PathToExtendedFieldType;
use \jamesiarmes\PhpEws\Type\PathToUnindexedFieldType;
use \jamesiarmes\PhpEws\Type\RestrictionType;
use \jamesiarmes\PhpEws\Type\SetItemFieldType;

use \Pandoc\Pandoc;

class EmailInterface {

	const MEDIAWIKI_ALLOWED_HTML = array(
		'<abbr>', '<b>', '<bdi>', '<bdo>', '<big>', '<blockquote>', '<br>',
		'<caption>', '<center>', '<cite>', '<code>', '<data>', '<dd>',
		'<del>', '<dfn>', '<div>', '<dl>', '<dt>', '<em>', '<font>', '<h1>',
		'<h2>', '<h3>', '<h4>', '<h5>', '<h6>', '<hr>', '<i>', '<ins>',
		'<kbd>', '<li>', '<mark>', '<ol>', '<p>', '<pre>', '<q>', '<rb>',
		'<rp>', '<rt>', '<rtc>', '<ruby>', '<s>', '<samp>', '<small>',
		'<span>', '<strike>', '<strong>', '<sub>', '<sup>', '<table>',
		'<td>', '<th>', '<time>	', '<tr>', '<tt>', '<u>', '<ul>', '<var>',
		'<wbr>'
	);

	protected $config;

	protected $client;

	function __construct() {

		// Grab configuration file
		$this->config = include( 'config.php' );

		// Build 'Client' object
		$this->client = new Client( $this->config->host, $this->config->username, $this->config->password, $this->config->exchange_version );
		$this->client->setTimezone( $this->config->timezone );

		// Get an array of unread e-mail IDs
		$emailIds = $this->getEmailIds();

		if ( !empty( $emailIds ) ) {
			// Get email contents from email IDs
			$emails = $this->getEmailsById( $emailIds );

			if ( !empty( $emails ) ) {

				// Print emails
				foreach ( $emails as $email ) {
					echo "Subject: " . $this->friendlyTitle( $email['subject'] ) .
						"\nID: " . $email['id'] . "\nBody: " .
						$email['body'] .
						"\n\n";
					$this->processEmail( $email );
				}

				// Attempt to hit the API URL
				$ch = curl_init( $this->config->wiki . "?action=exchangetowiki" );
				curl_setopt( $ch, CURLOPT_HEADER, 0 );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
				curl_exec( $ch );
				curl_close( $ch );
			}
		}
	}

	/**
	 * Retrieve array of email IDs to process for message contents
	 *
	 * @return array $emailIds Array of email IDs to be used to retrieve message contents.
	 */
	function getEmailIds() {

		$request = new FindItemType();
		// Set up request to only grab email-ids first
		$request->ItemShape = new ItemResponseShapeType();
		$request->ItemShape->BaseShape = DefaultShapeNamesType::ID_ONLY;
		$request->ItemShape->BodyType = BodyTypeResponseType::BEST;

		// Build restriction for unread messages
		$unread = new IsEqualToType();
		$unread->FieldURI = new PathToUnindexedFieldType();
		$unread->FieldURI->FieldURI = UnindexedFieldURIType::MESSAGE_IS_READ;
		$unread->FieldURIOrConstant = new FieldURIOrConstantType();
		$unread->FieldURIOrConstant->Constant = new ConstantValueType();
		$unread->FieldURIOrConstant->Constant->Value = "0";

		// Build restriction for subject field prefix for WikiPage
		$subjectPrefixWiki = new ContainsExpressionType();
		$subjectPrefixWiki->FieldURI = new PathToUnindexedFieldType();
		$subjectPrefixWiki->FieldURI->FieldURI = UnindexedFieldURIType::ITEM_SUBJECT;
		$subjectPrefixWiki->Constant = new ConstantValueType();
		$subjectPrefixWiki->Constant->Value = $this->config->prefix_wiki;
		// Ignore case
		$subjectPrefixWiki->ContainmentComparison = new ContainmentComparisonType();
		$subjectPrefixWiki->ContainmentComparison->_ = ContainmentComparisonType::IGNORE_CASE;
		// Ensure WikiPage is prefix
		$subjectPrefixWiki->ContainmentMode = new ContainmentModeType();
		$subjectPrefixWiki->ContainmentMode->_ = ContainmentModeType::PREFIXED;

		// Build restriction for subject field prefix for PandocPage
		$subjectPrefixPandoc = new ContainsExpressionType();
		$subjectPrefixPandoc->FieldURI = new PathToUnindexedFieldType();
		$subjectPrefixPandoc->FieldURI->FieldURI = UnindexedFieldURIType::ITEM_SUBJECT;
		$subjectPrefixPandoc->Constant = new ConstantValueType();
		$subjectPrefixPandoc->Constant->Value = $this->config->prefix_pandoc;
		// Ignore case
		$subjectPrefixPandoc->ContainmentComparison = new ContainmentComparisonType();
		$subjectPrefixPandoc->ContainmentComparison->_ = ContainmentComparisonType::IGNORE_CASE;
		// Ensure PandocPage is prefix
		$subjectPrefixPandoc->ContainmentMode = new ContainmentModeType();
		$subjectPrefixPandoc->ContainmentMode->_ = ContainmentModeType::PREFIXED;

		// Logical OR two prefixes together
		$subjectPrefixOr = new OrType();
		$subjectPrefixOr->Contains[] = $subjectPrefixPandoc;
		$subjectPrefixOr->Contains[] = $subjectPrefixWiki;

		// Build restriction for subject field pin number
		$subjectPin = new ContainsExpressionType();
		$subjectPin->FieldURI = new PathToUnindexedFieldType();
		$subjectPin->FieldURI->FieldURI = UnindexedFieldURIType::ITEM_SUBJECT;
		$subjectPin->Constant = new ConstantValueType();
		$subjectPin->Constant->Value = $this->config->prefix_pin;
		// Pin should be exact match
		$subjectPin->ContainmentComparison = new ContainmentComparisonType();
		$subjectPin->ContainmentComparison->_ = ContainmentComparisonType::EXACT;
		// Pin should be substring IE subject = 'WikiPage 11111:Page Title'
		$subjectPin->ContainmentMode = new ContainmentModeType();
		$subjectPin->ContainmentMode->_ = ContainmentModeType::SUBSTRING;

		// Construct an additional And to join multiple subject contains conditions
		$innerAnd = new AndType();
		$innerAnd->IsEqualTo = $unread;
		$innerAnd->Or = $subjectPrefixOr;

		$request->Restriction = new RestrictionType();
		$request->Restriction->And = new AndType();
		$request->Restriction->And->And = $innerAnd;
		$request->Restriction->And->Contains = $subjectPin;

		$request->IndexedPageItemView = new IndexedPageViewType();
		$request->IndexedPageItemView->BasePoint = 'Beginning';
		$request->IndexedPageItemView->Offset = 0;

		$request->ParentFolderIds = new NonEmptyArrayOfBaseFolderIdsType();

		// Search in the user's inbox.
		$folderId = new DistinguishedFolderIdType();
		$folderId->Id = DistinguishedFolderIdNameType::INBOX;
		$request->ParentFolderIds->DistinguishedFolderId[] = $folderId;

		$request->Traversal = ItemQueryTraversalType::SHALLOW;

		// Return all message properties.
		$response = $this->client->FindItem( $request );
		$emailIds = array();

		// Iterate over the results, printing any error messages or message subjects.
		$responseMessages = $response->ResponseMessages->FindItemResponseMessage;
		foreach ( $responseMessages as $responseMessage ) {
		    // Make sure the request succeeded.
		    if ( $responseMessage->ResponseClass != ResponseClassType::SUCCESS ) {
		        $code = $responseMessage->ResponseCode;
		        $message = $responseMessage->MessageText;
		        $this->add_to_log( "Failed to search for messages with \"$code: $message\"" );
		        continue;
		    }

		    // Iterate over the messages that were found, printing the subject for each.
		    $items = $responseMessage->RootFolder->Items->Message;
		    foreach ( $items as $item ) {
		        $subject = $item->Subject;
		        $id = $item->ItemId->Id;
			    $this->add_to_log( "$subject: $id" );
				// Add email id to array for description requests
				array_push( $emailIds, $id );
		    }
		}

		return $emailIds;
	}

	/**
	 * Request further message details with a second request using particular email IDs
	 *
	 * @param array $emailIds Array of email IDs to be used in detailed message request.
	 *
	 * @return array $emails Array of emails matching configured prefixes and pin number
	 */
	function getEmailsById( $emailIds ) {
		// Create empty array to add emails to
		$emails = array();

		$request = new GetItemType();
		$request->ItemShape = new ItemResponseShapeType();
		$request->ItemShape->BaseShape = DefaultShapeNamesType::ALL_PROPERTIES;
		$request->ItemShape->AdditionalProperties = new NonEmptyArrayOfPathsToElementType();
		$request->ItemIds = new NonEmptyArrayOfBaseItemIdsType();

		$property = new PathToExtendedFieldType();
		$property->PropertyTag = '0x1081';
		$property->PropertyType = MapiPropertyTypeType::INTEGER;
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = $property;

		foreach ( $emailIds as $email_id ) {
			$item = new ItemIdType();
			$item->Id = $email_id;
			$request->ItemIds->ItemId[] = $item;
		}

		$response = $this->client->GetItem( $request );

		$responseMessages = $response->ResponseMessages->GetItemResponseMessage;
		foreach ( $responseMessages as $responseMessage ) {
			if ( $responseMessage->ResponseClass != ResponseClassType::SUCCESS ) {
				$code = $responseMessage->ResponseCode;
				$message = $responseMessage->MessageText;
				$this->add_to_log( "Failed to get message with \"$code: $message\"" );
				continue;
			}

			foreach ( $responseMessage->Items->Message as $item ) {
				$email = array();
				$email['id'] = $item->ItemId->Id;
				$email['changekey'] = $item->ItemId->ChangeKey;
				$email['subject'] = $item->Subject;
				$email['body'] = $item->Body->_;
				$emails[] = $email;
			}
		}

		return $emails;
	}

	/**
	 * Mark a given email id with associated changekey as read in an exchange inbox
	 *
	 * @param string $email_id Exchange email ID of a particular email
	 * @param string $changekey Exchange changekey of the same particular email
	 **/
	function markEmailAsRead( $email_id, $changekey ) {
		$request = new UpdateItemType();
		$request->ConflictResolution = 'AlwaysOverwrite';
		$request->ItemChanges = array();

		$change = new ItemChangeType();
		$change->ItemId = new ItemIdType();
		$change->ItemId->Id = $email_id;
		$change->ItemId->ChangeKey = $changekey;

		$field = new SetItemFieldType();
		$field->FieldURI = new PathToUnindexedFieldType();
		$field->FieldURI->FieldURI = UnindexedFieldURIType::MESSAGE_IS_READ;
		$field->Message = new MessageType();
		$field->Message->IsRead = True;
		$field->FieldURIOrConstant = new FieldURIOrConstantType();
		$field->FieldURIOrConstant->Constant = new ConstantValueType();
		$field->FieldURIOrConstant->Constant->Value = "1";

		$change->Updates->SetItemField[] = $field;

		$request->ItemChanges[] = $change;

		$response = $this->client->UpdateItem( $request );
	}

	/**
	 * Process a given email array by creating a temp directory and file for the email.
	 *
	 * @param array $email Email associative array with id, changekey, subject, and body
	 *
	 * @return null Return from function on failure
	 **/
	function processEmail( $email ) {
		// Check we don't have an empty email, post-processing
		if ( $email['body'] != '' ) {
			$this->add_to_log( "Processing begun for '$email[subject]'." );
			// Determine if we want to strip html tags or convert to wikitext using Pandoc
			if ( $this->starts_with( $email['subject'], $this->config->prefix_wiki ) ) {
				$this->add_to_log( "Handling WikiText for '$email[subject]'." );
				$contents = trim( strip_tags( $email['body'], $this::MEDIAWIKI_ALLOWED_HTML ) );
			} elseif ( $this->starts_with ( $email['subject'], $this->config->prefix_pandoc ) ) {
				$this->add_to_log( "Running Pandoc for '$email[subject]'." );
				$pandoc = new Pandoc();
				$contents = $pandoc->convert( $email['body'], "html", "mediawiki" );
			} else {
				return;
			}
			$dir = $this->friendlyTitle( $email['subject'] );

			// Check if file has already been made for this email
			if ( !file_exists( $this->config->extension_path . "/ExchangeToWiki.tmp/$dir" ) ) {
				$this->add_to_log( "Creating directory for '$email[subject]'." );

				mkdir( $this->config->extension_path . "/ExchangeToWiki.tmp/$dir", 0777, true );
				chown( $this->config->extension_path . "/ExchangeToWiki.tmp/$dir", $this->config->user );

				$this->add_to_log( "Creating tmp file for '$email[subject]'." );

				$fh = fopen( $this->config->extension_path . "/ExchangeToWiki.tmp/$dir/_BODYTEXT_", 'a' );
				fwrite( $fh, $contents );
				fclose( $fh );
				chown( $this->config->extension_path . "/ExchangeToWiki.tmp/$dir/_BODYTEXT_", $this->config->user );

				$this->add_to_log( "Finished tmp file for $email[subject]." );

				$this->add_to_log( "Marking '$email[subject]' as read." );
				$this->markEmailAsRead( $email['id'], $email['changekey'] );
			} else {
				// Echo a realpath in case of directory issues
				echo "Can't access directory: '" . realpath( "./ExchangeToWiki.tmp/$dir" ) . "'.";
				$this->add_to_log( "Can't access directory: '" . realpath( "./ExchangeToWiki.tmp/$dir" ) . "'." );
			}
		}
	}

	/**
	 * Search string $haystack for string $needle at beginning of string.
	 *
	 * @param string $haystack String to be searched
	 * @param string $needle String to be searched for
	 *
	 * @return boolean
	 **/
	public static function starts_with( $haystack, $needle ) {
		if ( $needle != '' && strpos( $haystack, $needle ) === 0 ) return true;
		return false;
	}

	/**
	 * Append a message to the log file.
	 *
	 * @param string $message Message to be appended to log file.
	 *
	 * @return string $message Return message if and when successful
	 **/
	function add_to_log( $message ) {
		// Open log file
		$fh = fopen( './EmailInterface.log', 'a' );
		$time = date( 'd M Y, H:i:s' );
		// Write time and message
		fwrite( $fh, "[$time]: $message\n" );
		// Close log file
		fclose( $fh );
		// Return if and when successful
		return $message;
	}

	/**
	 * Take a potential Wiki Page Title and strip out any bad characters.
	 *
	 * @param string $subject Email subject, raw
	 *
	 * @return string $subject Cleaned wiki page title
	 **/
	function friendlyTitle( $subject ) {
		$title = $subject;

		// First strip prefix and pin number from subject
		$title = str_replace( $this->config->prefix_wiki, '', $title );
		$title = str_replace( $this->config->prefix_pandoc, '', $title );
		$title = str_replace( $this->config->prefix_pin, '', $title );

		// Replace any opening brackets with open parenthesis
		$title = str_replace( array( "{", "[", "<" ), "(", $title );
		// Replace any closing brackets with closing parenthesis
		$title = str_replace( array( "}", "]", ">" ), ")", $title );
		// Strip any other strange characters
		$title = str_replace( array( "#", "|", "\\", "+", "/", "-" ), '', $title );

		// Strip any remaining leading white-space
		$title = trim( $title, "\t\n\r " );

		return $title;
	}
}

// Construct EmailInterface object
$emailInt = new EmailInterface();
?>