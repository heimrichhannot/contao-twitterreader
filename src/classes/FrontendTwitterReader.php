<?php

/**
 * Twitter Reader for Contao Open Source CMS
 *
 * Copyright (C) 2013 Stefan Lindecke <lindesbs@googlemail.com>
 *
 * @package     twitterreader
 * @license     http://gplv3.fsf.org/ GPL
 * @filesource  https://github.com/lindesbs/TwitterReader
 */

/**
 * Class FrontendTwitterReader
 *
 * @copyright   GPL
 * @author      Stefan Lindecke
 * @package     Controller
 */
class FrontendTwitterReader extends Module
{

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'twitterreader_standard';


    public function generate()
	{
		if (TL_MODE == 'BE')
		{
			$objTemplate = new BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### TWITTER READER ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = $this->Environment->script.'?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		if ($this->twittertemplate)
		{
    		$this->strTemplate = $this->twittertemplate;
		}

		return parent::generate();
	}


    /**
     * Generate module
     */
    protected function compile()
    {
        $sqlTwitter=$this->Database->prepare("SELECT * FROM tl_module WHERE id=?")->limit(1)->execute($this->id);

        $objFeedBackup=$sqlTwitter->twitterFeedBackup;

        $objFeed=json_decode($objFeedBackup);

        $UpdateRange=10;
        // check only, if last check is longer than 1 minute old.
        $actualTime=time();

        if ((($actualTime - $sqlTwitter->twitterLastUpdate) > $UpdateRange) || (!is_array($objFeed)))
        {
            $oauth=new TwitterOAuth(TWITTERREADER_CONSUMER_KEY, TWITTERREADER_CONSUMER_SECRET, $GLOBALS['TL_CONFIG']['twitterreader_credentials_oauth_token'], $GLOBALS['TL_CONFIG']['twitterreader_credentials_oauth_token_secret']);

            $oauth->format='json';

            $arrFeed=array(
                'include_entities' => true,
                'count' => $this->twittercount,
                'include_rts' => true
            );

            if ($this->twitter_requesttype == 'user_timeline')
            {
                $arrFeed['screen_name']=$this->twitterusers;
            }

            $objFeed=$oauth->get('statuses/' . $this->twitter_requesttype, $arrFeed);

            if (count($objFeed->errors) > 0)
            {
                $this->log($objFeed->errors[0]->message, 'TwitterReader', TL_ERROR);
            }
            else
            {
                if (is_array($objFeed))
                {
                    $arrSet=array(
                        'twitterLastUpdate' => time(),
                        'twitterFeedBackup' => json_encode($objFeed)
                    );

                    $objDBFeed=$this->Database->prepare("UPDATE tl_module %s WHERE id=?")->set($arrSet)->execute($this->id);
                }
                else
                {
                    $sqlTwitter=$this->Database->prepare("UPDATE tl_module SET twitterFeedBackup=NULL WHERE id=?")->executeUncached($this->id);
                }
            }
        }

        if (!is_array($objFeed))
        {
            $this->log('JSON    dump for Twitter module not correct. Module ID "' . $this->id . '"', 'TwitterReader', TL_ERROR);

            return;
        }

        foreach ($objFeed as $item)
        {
            $textOutput=$item->text;
            $showItem=true;

            if ($this->twitterEnableHTTPLinks && $item->entities->urls)
            {
                foreach ($item->entities->urls as $url)
                {
                    $textOutput=str_replace($url->url, sprintf('<a title="%s" href="%s" %s>%s</a>', $url->expanded_url, $url->expanded_url, LINK_NEW_WINDOW_BLUR, $url->url), $textOutput);
                }
            }

            if ($this->twitterEnableMediaLinks && $item->extended_entities->media)
            {
                foreach ($item->extended_entities->media as $media)
                {
                    $textOutput=str_replace($media->url, sprintf('<a title="%s" href="%s" %s>%s</a>', $media->expanded_url, $media->expanded_url, LINK_NEW_WINDOW_BLUR, $media->display_url), $textOutput);
                }
            }

            if ($this->twitterEnableUserProfileLink && $item->entities->user_mentions)
            {
                foreach ($item->entities->user_mentions as $mention)
                {
                    $textOutput=str_replace('@' . $mention->screen_name, sprintf('<a title="%s" href="https://www.twitter.com/%s" %s>@%s</a>', $mention->name, $mention->screen_name, LINK_NEW_WINDOW_BLUR, $mention->screen_name), $textOutput);
                }
            }

            if ($this->twitterEnableHashtagLink && $item->entities->hashtags)
            {
                foreach ($item->entities->hashtags as $hashtag)
                {
                    $textOutput=str_replace('#' . $hashtag->text, sprintf('<a title="%s" href="https://www.twitter.com/search?q=%s" %s>#%s</a>', $hashtag->text, $hashtag->text, LINK_NEW_WINDOW_BLUR, $hashtag->text), $textOutput);
                }
            }
            
            if ($this->twitterEnableMediaLinks && $this->twitterEmbedFirstMedia && $item->extended_entities->media)
            {
                // only embed the first media
                $media = $item->extended_entities->media[0];
                $size = deserialize($this->twitterEmbedFirstMediaSize, true);
                
                if ($media->type == 'photo')
                {
                  $textOutput .= '<img style="width: ' . $size[0] . $size[2] . ';height: ' . $size[1] . $size[2] . ';" src="' . $media->media_url_https . '" />';
                }
                else if ($media->type == 'video')
                {
                  $video = $media->video_info->variants[5];
                  $textOutput .= '<iframe class="autosized-media" frameborder="0" allowfullscreen="" style="width: ' . $size[0] . $size[2] . ';height: ' . $size[1] . $size[2] . ';" src="https://amp.twimg.com/amplify-web-player/prod/source.html?video_url=' . urlencode($video->url) . '&amp;content_type=' . urlencode($video->content_type) . '&amp;image_src=' . urlencode($media->media_url_https) . '"></iframe>';
                }
            }

            $item->text=$textOutput;

            $item->First='';
            $item->Last='';
            $item->EvenOdd=($counter % 2) ? 'odd' : 'even';

            $counter++;

            if ($showItem)
            {
                $arrItems[]=$item;
            }

            if (count($arrItems) > $this->twittercount)
            {
                break;
            }
        }

        if (count($arrItems) > 2)
        {
            $arrItems[0]->First='first';
            $arrItems[count($arrItems) - 1]->Last='last';
        }

        $this->Template->TwitterData=$arrItems;
        $this->Template->TwitterCount=$this->twittercount;
    }
}