function Link (link)
  -- drop any Markdown or MediaWiki file extensions that might have snuck in.
  link.target = string.gsub(link.target, '%.md$', '')
  link.target = string.gsub(link.target, '%.mediawiki$', '')

  -- Add HTML file extension.
  link.target = link.target .. '.html'
  return link
end

