function Link (link)
  local absolute_path = link.target:sub(1,1) == '/'
  local external_url = not absolute_path and link.target:find("%a+://") == 1
  local isInternalSection = link.target:sub(1,1) == '#'
  if isInternalSection then
    link.target = link.target:lower()
  end

  -- drop any Markdown or MediaWiki file extensions that might have snuck in.
  if not external_url then
    link.target = string.gsub(link.target, '%.md$', '')
    link.target = string.gsub(link.target, '%.mediawiki$', '')
  end

  -- !!! FIXME: this doesn't work with subdirs, figure out why this was like this at all.
  -- If it's not an absolute path, not an external URL, and not a section link, make it absolute.
  --if not absolute_path and not external_url and not isInternalSection then
  --  link.target = '/' .. link.target
  --end
  return link
end

function Header(header)
    if header.level >= 2 then
        local returnHeader = header
        returnHeader.attributes['class'] = 'anchorText'
        local svg = pandoc.Image('', '/static_files/link.svg')
        svg.attributes['class'] = 'anchorImage'
        svg.attributes['width'] = '16'
        svg.attributes['height'] = '16'

        local link = pandoc.Link('', '#' .. returnHeader.identifier)
        local linkContents = pandoc.List(link.content)
        linkContents:insert(#linkContents + 1, svg)

        local content = pandoc.List(returnHeader.content)
        content:insert(#content + 1, link)

        returnHeader.content = content
        return returnHeader
    end
    return header
end
