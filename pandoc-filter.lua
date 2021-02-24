function Link (link)
  if link.target:sub(1,1) ~= '/' then
    link.target = '/' .. link.target
  end
  return link
end

