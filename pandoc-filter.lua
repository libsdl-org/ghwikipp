function Link (link)
  local absolute_path = link.target:sub(1,1) == '/'
  local external_url = not absolute_path and link.target:find("%a+://") == 1
  -- If it's not an absolute path and not an external URL, make it absolute.
  if not absolute_path and not external_url then
    link.target = '/' .. link.target
  end
  return link
end

